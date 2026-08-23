<?php

namespace justinholtweb\visorr\services;

use Craft;
use craft\db\Query;
use craft\db\Table as CraftTable;
use justinholtweb\visorr\db\Table;
use yii\base\Component;

/**
 * Where the revision history is, and what it costs.
 *
 * The report exists because revision bloat is never spread evenly. A site with a hundred
 * thousand revisions does not have a hundred thousand small problems — it has two sections
 * nobody thinks about, one of them full of Matrix blocks, generating a megabyte per save. A
 * global `maxRevisions` cannot see that, and a per-section policy is useless until you know
 * which sections to write one for.
 *
 * Every byte figure here is an estimate, and deliberately labelled as one: a revision's true
 * cost is spread over five tables and their indexes. What the numbers are reliable at is the
 * *ratio* between one section and another, which is the only thing the report is used for.
 */
class Storage extends Component
{
    /** Matches the per-row overhead {@see Revisions} uses, so the two screens agree. */
    private const ROW_OVERHEAD_BYTES = 400;

    /**
     * Site-wide headline numbers.
     *
     * @return array{revisions: int, elements: int, bytes: int, pinned: int, largest: int}
     */
    public function overview(): array
    {
        $row = (new Query())
            ->select([
                'revisions' => 'COUNT(*)',
                'elements' => 'COUNT(DISTINCT [[r.canonicalId]])',
            ])
            ->from(['r' => CraftTable::REVISIONS])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.revisionId]] = [[r.id]]')
            ->where(['e.dateDeleted' => null])
            ->one();

        $bytes = $this->totalBytes();

        return [
            'revisions' => (int)($row['revisions'] ?? 0),
            'elements' => (int)($row['elements'] ?? 0),
            'bytes' => $bytes,
            'pinned' => (int)(new Query())->from(Table::PINS)->count(),
            'largest' => $this->largestRevisionBytes(),
        ];
    }

    /**
     * Revision cost broken down by section, heaviest first.
     *
     * @return array<int, array{sectionId: int|null, name: string, handle: string, revisions: int, elements: int, bytes: int, perElement: float}>
     */
    public function bySection(): array
    {
        $own = $this->groupedOwnBytes('en.sectionId', [
            'entriesJoin' => true,
        ]);

        $nested = $this->groupedNestedBytes('owner_en.sectionId', true);
        $sections = Craft::$app->getEntries()->getAllSections();
        $sectionsById = [];

        foreach ($sections as $section) {
            $sectionsById[(int)$section->id] = $section;
        }

        $rows = [];

        foreach ($own as $key => $stats) {
            $sectionId = $key !== '' ? (int)$key : null;
            $section = $sectionId !== null ? ($sectionsById[$sectionId] ?? null) : null;

            $bytes = $stats['bytes'] + ($nested[$key]['bytes'] ?? 0);

            $rows[] = [
                'sectionId' => $sectionId,
                'name' => $section?->name ?? Craft::t('visorr', 'Not in a section'),
                'handle' => $section?->handle ?? '',
                'uid' => $section?->uid ?? '',
                'revisions' => $stats['revisions'],
                'elements' => $stats['elements'],
                'bytes' => $bytes,
                'perElement' => $stats['elements'] > 0 ? $bytes / $stats['elements'] : 0.0,
            ];
        }

        usort($rows, fn(array $a, array $b) => $b['bytes'] <=> $a['bytes']);

        return $rows;
    }

    /**
     * Revision cost broken down by element type.
     *
     * @return array<int, array{elementType: string, name: string, revisions: int, elements: int, bytes: int}>
     */
    public function byElementType(): array
    {
        $own = $this->groupedOwnBytes('ce.type');
        $nested = $this->groupedNestedBytes('owner_ce.type', false);

        $rows = [];

        foreach ($own as $type => $stats) {
            /** @var class-string $type */
            $rows[] = [
                'elementType' => (string)$type,
                'name' => class_exists($type) && method_exists($type, 'displayName')
                    ? $type::displayName()
                    : (string)$type,
                'revisions' => $stats['revisions'],
                'elements' => $stats['elements'],
                'bytes' => $stats['bytes'] + ($nested[$type]['bytes'] ?? 0),
            ];
        }

        usort($rows, fn(array $a, array $b) => $b['bytes'] <=> $a['bytes']);

        return $rows;
    }

    /**
     * The elements carrying the most history — the ones a retention policy should name.
     *
     * @return array<int, array{canonicalId: int, title: string, elementType: string, revisions: int, bytes: int}>
     */
    public function topElements(int $limit = 25): array
    {
        $lengthExpr = $this->contentLength('es');

        $rows = (new Query())
            ->select([
                'canonicalId' => 'r.canonicalId',
                'elementType' => 'ce.type',
                'revisions' => 'COUNT(DISTINCT [[r.id]])',
                'bytes' => "SUM($lengthExpr)",
            ])
            ->from(['r' => CraftTable::REVISIONS])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.revisionId]] = [[r.id]]')
            ->innerJoin(['ce' => CraftTable::ELEMENTS], '[[ce.id]] = [[r.canonicalId]]')
            ->innerJoin(['es' => CraftTable::ELEMENTS_SITES], '[[es.elementId]] = [[e.id]]')
            ->where(['e.dateDeleted' => null])
            ->groupBy(['r.canonicalId', 'ce.type'])
            ->orderBy(['bytes' => SORT_DESC])
            ->limit($limit)
            ->all();

        if ($rows === []) {
            return [];
        }

        $canonicalIds = array_map(fn(array $row) => (int)$row['canonicalId'], $rows);
        $titles = $this->titlesFor($canonicalIds);
        $nested = $this->nestedBytesByCanonical($canonicalIds);

        return array_map(function(array $row) use ($titles, $nested) {
            $canonicalId = (int)$row['canonicalId'];

            return [
                'canonicalId' => $canonicalId,
                'title' => $titles[$canonicalId] ?? Craft::t('visorr', 'Untitled'),
                'elementType' => (string)$row['elementType'],
                'revisions' => (int)$row['revisions'],
                'bytes' => (int)$row['bytes'] + ($nested[$canonicalId] ?? 0),
            ];
        }, $rows);
    }

    /**
     * A human file size, so every screen spells it the same way.
     */
    public function formatBytes(int|float $bytes): string
    {
        return Craft::$app->getFormatter()->asShortSize($bytes, 1);
    }

    /**
     * Total bytes, counted the same way the breakdowns count them — content plus a fixed
     * overhead per row. The headline has to equal the sum of the table below it, or the first
     * thing anyone does with this screen is stop believing it.
     */
    private function totalBytes(): int
    {
        $lengthExpr = $this->contentLength('es');

        $own = (new Query())
            ->select(['bytes' => "SUM($lengthExpr)", 'rows' => 'COUNT(*)'])
            ->from(['e' => CraftTable::ELEMENTS])
            ->innerJoin(['es' => CraftTable::ELEMENTS_SITES], '[[es.elementId]] = [[e.id]]')
            ->where(['not', ['e.revisionId' => null]])
            ->andWhere(['e.dateDeleted' => null])
            ->one();

        $nested = (new Query())
            ->select(['bytes' => "SUM($lengthExpr)", 'rows' => 'COUNT(*)'])
            ->from(['ne' => CraftTable::ENTRIES])
            ->innerJoin(['nel' => CraftTable::ELEMENTS], '[[nel.id]] = [[ne.id]]')
            ->innerJoin(['es' => CraftTable::ELEMENTS_SITES], '[[es.elementId]] = [[ne.id]]')
            ->innerJoin(['owner' => CraftTable::ELEMENTS], '[[owner.id]] = [[ne.primaryOwnerId]]')
            ->where(['not', ['owner.revisionId' => null]])
            ->andWhere(['nel.dateDeleted' => null, 'owner.dateDeleted' => null])
            ->one();

        return (int)($own['bytes'] ?? 0) + ((int)($own['rows'] ?? 0) * self::ROW_OVERHEAD_BYTES)
            + (int)($nested['bytes'] ?? 0) + ((int)($nested['rows'] ?? 0) * self::ROW_OVERHEAD_BYTES);
    }

    /**
     * The heaviest single revision, counting the nested elements it owns.
     *
     * The candidates are the fifty biggest by their own content, and the nested weight is added
     * only to those. Asking the question exactly would mean summing nested bytes for every
     * revision on the site — a table scan to fill in one number on a summary card. Fifty is
     * comfortably enough for the answer to be right in practice, and the card says "estimated".
     */
    private function largestRevisionBytes(): int
    {
        $lengthExpr = $this->contentLength('es');

        $candidates = (new Query())
            ->select(['elementId' => 'e.id', 'bytes' => "SUM($lengthExpr)", 'rows' => 'COUNT(*)'])
            ->from(['e' => CraftTable::ELEMENTS])
            ->innerJoin(['es' => CraftTable::ELEMENTS_SITES], '[[es.elementId]] = [[e.id]]')
            ->where(['not', ['e.revisionId' => null]])
            ->andWhere(['e.dateDeleted' => null])
            ->groupBy(['e.id'])
            ->orderBy(['bytes' => SORT_DESC])
            ->limit(50)
            ->all();

        if ($candidates === []) {
            return 0;
        }

        $totals = [];

        foreach ($candidates as $row) {
            $totals[(int)$row['elementId']] = (int)$row['bytes'] + ((int)$row['rows'] * self::ROW_OVERHEAD_BYTES);
        }

        $nested = (new Query())
            ->select(['ownerId' => 'ne.primaryOwnerId', 'bytes' => "SUM($lengthExpr)", 'rows' => 'COUNT(*)'])
            ->from(['ne' => CraftTable::ENTRIES])
            ->innerJoin(['nel' => CraftTable::ELEMENTS], '[[nel.id]] = [[ne.id]]')
            ->innerJoin(['es' => CraftTable::ELEMENTS_SITES], '[[es.elementId]] = [[ne.id]]')
            ->where(['ne.primaryOwnerId' => array_keys($totals)])
            ->andWhere(['nel.dateDeleted' => null])
            ->groupBy(['ne.primaryOwnerId'])
            ->all();

        foreach ($nested as $row) {
            $ownerId = (int)$row['ownerId'];
            $totals[$ownerId] = ($totals[$ownerId] ?? 0)
                + (int)$row['bytes']
                + ((int)$row['rows'] * self::ROW_OVERHEAD_BYTES);
        }

        return (int)max($totals);
    }

    /**
     * Content bytes of the revision elements themselves, grouped by an arbitrary column.
     *
     * @param array{entriesJoin?: bool} $options
     * @return array<string, array{revisions: int, elements: int, bytes: int}>
     */
    private function groupedOwnBytes(string $groupBy, array $options = []): array
    {
        $lengthExpr = $this->contentLength('es');

        $query = (new Query())
            ->select([
                'grouping' => $groupBy,
                'revisions' => 'COUNT(DISTINCT [[r.id]])',
                'elements' => 'COUNT(DISTINCT [[r.canonicalId]])',
                'bytes' => "SUM($lengthExpr)",
                'rows' => 'COUNT(*)',
            ])
            ->from(['r' => CraftTable::REVISIONS])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.revisionId]] = [[r.id]]')
            ->innerJoin(['ce' => CraftTable::ELEMENTS], '[[ce.id]] = [[r.canonicalId]]')
            ->innerJoin(['es' => CraftTable::ELEMENTS_SITES], '[[es.elementId]] = [[e.id]]')
            ->where(['e.dateDeleted' => null])
            ->groupBy([$groupBy]);

        if (!empty($options['entriesJoin'])) {
            $query->leftJoin(['en' => CraftTable::ENTRIES], '[[en.id]] = [[r.canonicalId]]');
        }

        $result = [];

        foreach ($query->all() as $row) {
            $result[(string)($row['grouping'] ?? '')] = [
                'revisions' => (int)$row['revisions'],
                'elements' => (int)$row['elements'],
                'bytes' => (int)$row['bytes'] + ((int)$row['rows'] * self::ROW_OVERHEAD_BYTES),
            ];
        }

        return $result;
    }

    /**
     * Content bytes of the *nested* elements those revisions own, grouped the same way.
     *
     * Two queries rather than one because joining both the revision's own content rows and its
     * nested elements' content rows in a single statement multiplies them together, and the
     * resulting sum is wrong by a factor nobody would notice until the report claimed a small
     * site was using nine gigabytes.
     *
     * @return array<string, array{bytes: int}>
     */
    private function groupedNestedBytes(string $groupBy, bool $entriesJoin): array
    {
        $lengthExpr = $this->contentLength('es');

        $query = (new Query())
            ->select([
                'grouping' => $groupBy,
                'bytes' => "SUM($lengthExpr)",
                'rows' => 'COUNT(*)',
            ])
            ->from(['ne' => CraftTable::ENTRIES])
            ->innerJoin(['nel' => CraftTable::ELEMENTS], '[[nel.id]] = [[ne.id]]')
            ->innerJoin(['es' => CraftTable::ELEMENTS_SITES], '[[es.elementId]] = [[ne.id]]')
            ->innerJoin(['owner' => CraftTable::ELEMENTS], '[[owner.id]] = [[ne.primaryOwnerId]]')
            ->innerJoin(['r' => CraftTable::REVISIONS], '[[r.id]] = [[owner.revisionId]]')
            ->innerJoin(['owner_ce' => CraftTable::ELEMENTS], '[[owner_ce.id]] = [[r.canonicalId]]')
            ->where(['nel.dateDeleted' => null, 'owner.dateDeleted' => null])
            ->groupBy([$groupBy]);

        if ($entriesJoin) {
            $query->leftJoin(['owner_en' => CraftTable::ENTRIES], '[[owner_en.id]] = [[r.canonicalId]]');
        }

        $result = [];

        foreach ($query->all() as $row) {
            $result[(string)($row['grouping'] ?? '')] = [
                'bytes' => (int)$row['bytes'] + ((int)$row['rows'] * self::ROW_OVERHEAD_BYTES),
            ];
        }

        return $result;
    }

    /**
     * @param int[] $canonicalIds
     * @return array<int, int>
     */
    private function nestedBytesByCanonical(array $canonicalIds): array
    {
        $lengthExpr = $this->contentLength('es');

        $rows = (new Query())
            ->select([
                'canonicalId' => 'r.canonicalId',
                'bytes' => "SUM($lengthExpr)",
                'rows' => 'COUNT(*)',
            ])
            ->from(['ne' => CraftTable::ENTRIES])
            ->innerJoin(['nel' => CraftTable::ELEMENTS], '[[nel.id]] = [[ne.id]]')
            ->innerJoin(['es' => CraftTable::ELEMENTS_SITES], '[[es.elementId]] = [[ne.id]]')
            ->innerJoin(['owner' => CraftTable::ELEMENTS], '[[owner.id]] = [[ne.primaryOwnerId]]')
            ->innerJoin(['r' => CraftTable::REVISIONS], '[[r.id]] = [[owner.revisionId]]')
            ->where(['r.canonicalId' => $canonicalIds])
            ->andWhere(['nel.dateDeleted' => null, 'owner.dateDeleted' => null])
            ->groupBy(['r.canonicalId'])
            ->all();

        $result = [];

        foreach ($rows as $row) {
            $result[(int)$row['canonicalId']] = (int)$row['bytes'] + ((int)$row['rows'] * self::ROW_OVERHEAD_BYTES);
        }

        return $result;
    }

    /**
     * @param int[] $elementIds
     * @return array<int, string>
     */
    private function titlesFor(array $elementIds): array
    {
        $rows = (new Query())
            ->select(['elementId', 'title'])
            ->from(CraftTable::ELEMENTS_SITES)
            ->where(['elementId' => $elementIds])
            ->andWhere(['siteId' => Craft::$app->getSites()->getPrimarySite()->id])
            ->all();

        $titles = [];

        foreach ($rows as $row) {
            $titles[(int)$row['elementId']] = (string)($row['title'] ?? '');
        }

        return $titles;
    }

    private function contentLength(string $alias): string
    {
        $column = "[[$alias.content]]";

        return Craft::$app->getDb()->getIsMysql()
            ? "COALESCE(LENGTH($column), 0)"
            : "COALESCE(LENGTH($column::text), 0)";
    }
}
