<?php

namespace justinholtweb\visorr\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use justinholtweb\visorr\db\Table;
use justinholtweb\visorr\models\RevisionInfo;
use justinholtweb\visorr\Plugin;
use Throwable;
use yii\base\Component;

/**
 * The read side of revision history.
 *
 * Everything here works on revisions Visorr did not create, which is the whole point: a plugin
 * installed on a five-year-old site is useful on day one because Craft's own `revisions` and
 * `elements` rows already contain the history. Visorr's own tables only add what Craft never
 * recorded.
 *
 * The queries are deliberately raw rather than element queries. Listing thirty revisions with
 * their authors and sizes is a metadata question; resolving thirty full elements to answer it
 * would load thirty copies of the entry's entire content, which on a Matrix-heavy page is
 * thousands of rows for a table that shows dates.
 */
class Revisions extends Component
{
    /**
     * Rough per-row overhead of a revision beyond its content payload: the `elements` row, the
     * `elements_sites` row, the type-specific row (`entries`, `assets`, …), and their indexes.
     * Measured against a handful of real sites and rounded; the report says "estimated" for a
     * reason, and the number that matters there is the ratio between sections, not the absolute.
     */
    private const ROW_OVERHEAD_BYTES = 400;

    /**
     * Every revision of an element, newest first.
     *
     * @param bool $withSizes Whether to work out what each revision weighs. Two extra queries;
     *   worth it on the storage report, wasted on the sidebar panel.
     * @return RevisionInfo[]
     */
    public function getRevisionInfos(
        ElementInterface $canonical,
        ?int $siteId = null,
        ?int $limit = null,
        bool $withSizes = false,
    ): array {
        $rows = $this->baseQuery()
            ->andWhere(['r.canonicalId' => $canonical->id])
            ->orderBy(['r.num' => SORT_DESC])
            ->limit($limit)
            ->all();

        if ($rows === []) {
            return [];
        }

        $infos = $this->hydrate($rows, $canonical, $withSizes);

        if ($siteId !== null) {
            $infos = Plugin::getInstance()->siteTracking->filterInfos($infos, $siteId);
        }

        return $infos;
    }

    /**
     * One revision's metadata, or null if it does not exist.
     */
    public function getRevisionInfo(int $revisionId): ?RevisionInfo
    {
        $row = $this->baseQuery()
            ->andWhere(['r.id' => $revisionId])
            ->one();

        if ($row === false || $row === null) {
            return null;
        }

        return $this->hydrate([$row], null, true)[0] ?? null;
    }

    /**
     * How many revisions an element has.
     */
    public function countFor(int $canonicalId): int
    {
        return (int)(new Query())
            ->from(['r' => CraftTable::REVISIONS])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.revisionId]] = [[r.id]]')
            ->where(['r.canonicalId' => $canonicalId, 'e.dateDeleted' => null])
            ->count();
    }

    /**
     * Load a revision as a full element, ready to read content from.
     *
     * `status(null)` and `revisions(true)` are both required — a revision of a disabled entry
     * is invisible to a default query, and so is every revision.
     */
    public function getRevisionElement(RevisionInfo $info, ?int $siteId = null, ?string $elementType = null): ?ElementInterface
    {
        $elementType ??= $this->elementTypeFor($info->elementId);

        if ($elementType === null || !class_exists($elementType)) {
            return null;
        }

        /** @var class-string<ElementInterface> $elementType */
        $query = $elementType::find()
            ->id($info->elementId)
            ->revisions(true)
            ->status(null)
            ->trashed(null);

        if ($siteId !== null) {
            $query->siteId($siteId);
        } else {
            $query->site('*')->unique();
        }

        return $query->one();
    }

    /**
     * A {@see RevisionInfo} describing the element as it stands right now.
     *
     * Not a real revision — it has no row in `revisions` — but the comparison screen needs to
     * offer "current" on both sides, and giving it the same shape as everything else keeps the
     * pickers, the URLs and the templates from growing a special case each.
     */
    public function currentInfo(ElementInterface $canonical): RevisionInfo
    {
        $info = new RevisionInfo([
            'elementId' => (int)$canonical->id,
            'revisionId' => 0,
            'canonicalId' => (int)$canonical->id,
            'num' => 0,
            'dateCreated' => $canonical->dateUpdated,
            'siteId' => $canonical->siteId,
            'isCurrent' => true,
        ]);

        $info->element = $canonical;

        return $info;
    }

    /**
     * Estimated bytes for a set of revision *elements*, including the nested elements they own.
     *
     * @param int[] $elementIds
     * @return array<int, int> element ID => bytes
     */
    public function sizesFor(array $elementIds): array
    {
        if ($elementIds === []) {
            return [];
        }

        $lengthExpr = $this->contentLengthExpression();
        $sizes = array_fill_keys($elementIds, 0);

        $ownRows = (new Query())
            ->select([
                'elementId' => 'elementId',
                'bytes' => "SUM($lengthExpr)",
                'rows' => 'COUNT(*)',
            ])
            ->from(CraftTable::ELEMENTS_SITES)
            ->where(['elementId' => $elementIds])
            ->groupBy(['elementId'])
            ->all();

        foreach ($ownRows as $row) {
            $sizes[(int)$row['elementId']] = (int)$row['bytes'] + ((int)$row['rows'] * self::ROW_OVERHEAD_BYTES);
        }

        // Nested elements are owned by the revision, so they are part of what it costs — and
        // on a page built out of Matrix blocks they are nearly all of what it costs.
        foreach ($this->nestedRowsFor($elementIds) as $row) {
            $ownerId = (int)$row['ownerId'];
            $sizes[$ownerId] = ($sizes[$ownerId] ?? 0)
                + (int)$row['bytes']
                + ((int)$row['rows'] * self::ROW_OVERHEAD_BYTES);
        }

        return $sizes;
    }

    /**
     * How many nested elements each revision owns.
     *
     * @param int[] $elementIds
     * @return array<int, int>
     */
    public function nestedCountsFor(array $elementIds): array
    {
        if ($elementIds === []) {
            return [];
        }

        $counts = array_fill_keys($elementIds, 0);

        foreach ($this->nestedRowsFor($elementIds) as $row) {
            $counts[(int)$row['ownerId']] = (int)$row['nested'];
        }

        return $counts;
    }

    /**
     * Element types that have revision history, as class => display name.
     *
     * Derived from the database rather than from the element classes, because
     * `hasRevisions()` is an *instance* method — for an entry the answer depends on its
     * section's versioning setting, so there is no static question to ask. Entries are always
     * offered even when nothing has been saved yet, since that is the type a first policy is
     * nearly always written for.
     *
     * @return array<string, string>
     */
    public function revisionableElementTypes(): array
    {
        $types = [Entry::class => Entry::displayName()];

        $found = (new Query())
            ->select(['ce.type'])
            ->distinct()
            ->from(['r' => CraftTable::REVISIONS])
            ->innerJoin(['ce' => CraftTable::ELEMENTS], '[[ce.id]] = [[r.canonicalId]]')
            ->column();

        foreach ($found as $type) {
            $type = (string)$type;

            if (!class_exists($type) || isset($types[$type])) {
                continue;
            }

            /** @var class-string<ElementInterface> $type */
            $types[$type] = $type::displayName();
        }

        asort($types);

        return $types;
    }

    /**
     * The element class of a given element ID, read straight off `elements.type`.
     */
    public function elementTypeFor(int $elementId): ?string
    {
        $type = (new Query())
            ->select(['type'])
            ->from(CraftTable::ELEMENTS)
            ->where(['id' => $elementId])
            ->scalar();

        return is_string($type) && $type !== '' ? $type : null;
    }

    /**
     * The canonical element behind a revision, loaded in the right site.
     */
    public function canonicalFor(int $canonicalId, ?int $siteId = null): ?ElementInterface
    {
        $elementType = $this->elementTypeFor($canonicalId);

        if ($elementType === null || !class_exists($elementType)) {
            return null;
        }

        try {
            return $this->loadCanonical($elementType, $canonicalId, $siteId);
        } catch (Throwable $e) {
            // An element whose section, volume or group has since been deleted throws on load.
            // Its revisions — and any pin on them — outlive it until garbage collection catches
            // up, and a screen listing pins must survive meeting one.
            Craft::warning("Visorr could not load canonical element #$canonicalId: {$e->getMessage()}", Plugin::LOG_CATEGORY);
            return null;
        }
    }

    /**
     * @param class-string<ElementInterface> $elementType
     */
    private function loadCanonical(string $elementType, int $canonicalId, ?int $siteId): ?ElementInterface
    {
        $query = $elementType::find()
            ->id($canonicalId)
            ->status(null)
            ->trashed(null)
            ->drafts(null)
            ->revisions(null);

        if ($siteId !== null) {
            $query->siteId($siteId);
        } else {
            $query->site('*')->unique();
        }

        return $query->one();
    }

    /**
     * The metadata query every listing starts from: one row per revision, joined to the
     * revision's element row and to Visorr's pin and site records.
     */
    private function baseQuery(): Query
    {
        return (new Query())
            ->select([
                'revisionId' => 'r.id',
                'canonicalId' => 'r.canonicalId',
                'num' => 'r.num',
                'notes' => 'r.notes',
                'creatorId' => 'r.creatorId',
                'elementId' => 'e.id',
                'elementType' => 'e.type',
                'dateCreated' => 'e.dateCreated',
                'pinLabel' => 'p.label',
                'pinnedAt' => 'p.dateCreated',
                'trackedSiteId' => 'rs.siteId',
            ])
            ->from(['r' => CraftTable::REVISIONS])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.revisionId]] = [[r.id]]')
            ->leftJoin(['p' => Table::PINS], '[[p.revisionId]] = [[r.id]]')
            ->leftJoin(['rs' => Table::REVISION_SITES], '[[rs.revisionId]] = [[r.id]]')
            ->where(['e.dateDeleted' => null]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return RevisionInfo[]
     */
    private function hydrate(array $rows, ?ElementInterface $canonical, bool $withSizes): array
    {
        $elementIds = array_map(fn(array $row) => (int)$row['elementId'], $rows);
        $sizes = $withSizes ? $this->sizesFor($elementIds) : [];
        $nested = $withSizes ? $this->nestedCountsFor($elementIds) : [];
        $creators = $this->creatorsFor(array_filter(ArrayHelper::getColumn($rows, 'creatorId')));

        // Craft's own revisions screen hides the revision whose creation date matches the
        // canonical's `dateUpdated` — it *is* the current state, so it says nothing new. Visorr
        // shows it instead, labelled, because "what changed since this was last saved" is a
        // question people ask and Craft answers by omission.
        $currentStamp = $canonical?->dateUpdated?->getTimestamp();

        $infos = [];

        foreach ($rows as $row) {
            $elementId = (int)$row['elementId'];
            $creatorId = $row['creatorId'] !== null ? (int)$row['creatorId'] : null;
            $date = DateTimeHelper::toDateTime($row['dateCreated']) ?: null;

            $info = new RevisionInfo([
                'elementId' => $elementId,
                'revisionId' => (int)$row['revisionId'],
                'canonicalId' => (int)$row['canonicalId'],
                'num' => (int)$row['num'],
                'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
                'creatorId' => $creatorId,
                'creatorName' => $creatorId !== null ? ($creators[$creatorId]?->name ?? null) : null,
                'siteId' => $row['trackedSiteId'] !== null ? (int)$row['trackedSiteId'] : null,
                'pinned' => $row['pinnedAt'] !== null,
                'pinLabel' => $row['pinLabel'] !== null ? (string)$row['pinLabel'] : null,
                'dateCreated' => $date ?: null,
                'sizeBytes' => $sizes[$elementId] ?? 0,
                'nestedCount' => $nested[$elementId] ?? 0,
                'isCurrent' => $currentStamp !== null && $date !== null && $date->getTimestamp() === $currentStamp,
            ]);

            $info->creator = $creatorId !== null ? ($creators[$creatorId] ?? null) : null;

            $infos[] = $info;
        }

        return $infos;
    }

    /**
     * @param int[] $creatorIds
     * @return array<int, User|null>
     */
    private function creatorsFor(array $creatorIds): array
    {
        $creatorIds = array_values(array_unique(array_map('intval', $creatorIds)));

        if ($creatorIds === []) {
            return [];
        }

        $users = User::find()
            ->id($creatorIds)
            ->status(null)
            ->indexBy('id')
            ->all();

        /** @var array<int, User|null> $users */
        return $users;
    }

    /**
     * Content bytes and row counts for the nested elements owned by each of the given elements.
     *
     * Nested elements are entries with a `primaryOwnerId`, which is how Craft models Matrix
     * blocks in 5.x — there is no separate block table any more.
     *
     * @param int[] $ownerIds
     * @return array<int, array<string, mixed>>
     */
    private function nestedRowsFor(array $ownerIds): array
    {
        $lengthExpr = $this->contentLengthExpression('es');

        return (new Query())
            ->select([
                'ownerId' => 'en.primaryOwnerId',
                'bytes' => "SUM($lengthExpr)",
                'rows' => 'COUNT(*)',
                'nested' => 'COUNT(DISTINCT [[en.id]])',
            ])
            ->from(['en' => CraftTable::ENTRIES])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[en.id]]')
            ->innerJoin(['es' => CraftTable::ELEMENTS_SITES], '[[es.elementId]] = [[en.id]]')
            ->where(['en.primaryOwnerId' => $ownerIds])
            ->andWhere(['e.dateDeleted' => null])
            ->groupBy(['en.primaryOwnerId'])
            ->all();
    }

    /**
     * `LENGTH()` over a JSON column, spelled the way the current driver spells it.
     *
     * Postgres will not apply `length()` to `json` without a cast, and MySQL will not accept
     * the cast syntax. There is no portable spelling, so there is a branch.
     */
    private function contentLengthExpression(string $alias = ''): string
    {
        $column = $alias !== '' ? "[[$alias.content]]" : '[[content]]';

        return Craft::$app->getDb()->getIsMysql()
            ? "COALESCE(LENGTH($column), 0)"
            : "COALESCE(LENGTH($column::text), 0)";
    }
}
