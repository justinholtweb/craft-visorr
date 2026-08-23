<?php

namespace justinholtweb\visorr\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\models\Section;
use DateInterval;
use DateTime;
use justinholtweb\visorr\db\Table;
use justinholtweb\visorr\models\PrunePlan;
use justinholtweb\visorr\models\PruneResult;
use justinholtweb\visorr\models\PruneScope;
use justinholtweb\visorr\models\Settings;
use justinholtweb\visorr\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Works out which revisions should go, and then removes them.
 *
 * **The invariant**: {@see resolve()} is the only thing in the plugin that decides what gets
 * deleted, and {@see apply()} deletes exactly the list it is handed. The dry run and the real
 * run are not two implementations that ought to agree — they are one implementation, called
 * twice. A preview that builds its own query is a preview of a different deletion.
 *
 * Resolution is done canonical element by canonical element because retention is a per-element
 * question ("keep the newest twenty of *these*"), but the work is batched: candidates are found
 * with one grouped query, and their revisions are read a few hundred elements at a time, so a
 * site-wide prune costs a bounded number of queries and a bounded amount of memory whether it
 * is looking at fifty entries or fifty thousand.
 */
class Pruning extends Component
{
    /** Canonical elements per revision-fetching query. */
    private const CANONICAL_CHUNK = 200;

    /** Revision elements loaded at once for deletion. */
    private const DELETE_CHUNK = 50;

    /**
     * Work out exactly what a prune would delete.
     *
     * @param int|null $limit Overrides the configured batch size. Pass a large number from the
     *   preview screen when you want the honest total rather than the next batch.
     */
    public function resolve(PruneScope $scope, ?int $limit = null): PrunePlan
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $limit ??= $settings->pruneBatchSize;

        $plan = new PrunePlan(['scope' => $scope]);
        $candidates = $this->candidates($scope);
        $plan->elementsScanned = count($candidates);

        if ($candidates === []) {
            return $plan;
        }

        $retention = Plugin::getInstance()->retention;
        $sectionUids = $this->sectionUidsById();

        foreach (array_chunk($candidates, self::CANONICAL_CHUNK, true) as $chunk) {
            $revisionsByCanonical = $this->revisionsFor(array_keys($chunk), $scope);

            foreach ($chunk as $canonicalId => $candidate) {
                $revisions = $revisionsByCanonical[$canonicalId] ?? [];

                if ($revisions === []) {
                    continue;
                }

                $rule = $retention->resolve(
                    (string)$candidate['elementType'],
                    $candidate['sectionId'] !== null ? ($sectionUids[(int)$candidate['sectionId']] ?? null) : null,
                    $scope->siteId,
                );

                $maxRevisions = $scope->purgeAll ? 0 : $rule->maxRevisions;
                $maxAgeDays = $scope->purgeAll ? null : $rule->maxAgeDays;
                $minKeep = $scope->purgeAll ? 0 : max(1, $rule->minKeep);

                if (!$scope->purgeAll && $maxRevisions === null && $maxAgeDays === null) {
                    // Nothing to enforce. Skipping here rather than letting the loop below fall
                    // through means an unlimited section costs nothing to scan.
                    continue;
                }

                $cutoff = $maxAgeDays !== null
                    ? (new DateTime())->sub(new DateInterval("P{$maxAgeDays}D"))
                    : null;

                $survivors = 0;

                foreach ($revisions as $revision) {
                    if ($revision['pinned']) {
                        $plan->protectedCount++;
                        $survivors++;
                        continue;
                    }

                    if ($survivors < $minKeep) {
                        $survivors++;
                        continue;
                    }

                    $reason = null;

                    if ($maxRevisions !== null && $survivors >= $maxRevisions) {
                        $reason = 'over-limit';
                    } elseif ($cutoff !== null && $revision['dateCreated'] !== null && $revision['dateCreated'] < $cutoff) {
                        $reason = 'expired';
                    }

                    if ($reason === null) {
                        $survivors++;
                        continue;
                    }

                    $plan->victims[] = [
                        'elementId' => (int)$revision['elementId'],
                        'revisionId' => (int)$revision['revisionId'],
                        'canonicalId' => $canonicalId,
                        'num' => (int)$revision['num'],
                        'bytes' => 0,
                        'dateCreated' => $revision['dateCreated']?->format('Y-m-d H:i:s'),
                        'reason' => $reason,
                    ];

                    if (count($plan->victims) >= $limit) {
                        $plan->truncated = true;
                        break 3;
                    }
                }
            }
        }

        $this->attachSizes($plan);
        $this->attachTitles($plan);

        return $plan;
    }

    /**
     * Delete the revisions a plan named.
     *
     * Deletion goes through `Elements::deleteElement()` rather than a `DELETE` statement, and
     * that is not fastidiousness: a revision owns its nested elements, and only Craft's own
     * delete path knows to take the Matrix blocks with it. Deleting the rows directly would
     * cascade the `entries` rows and orphan their `elements` rows — the storage would not come
     * back, which is the one thing a prune is for.
     */
    public function apply(PrunePlan $plan, string $trigger = 'cp', ?int $userId = null): PruneResult
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        $result = new PruneResult(['plannedCount' => $plan->count()]);
        $runs = Plugin::getInstance()->runs;
        $pins = Plugin::getInstance()->pins;
        $elementsService = Craft::$app->getElements();

        $result->runId = $runs->start($plan, $trigger, $userId);

        $bytesById = [];
        foreach ($plan->victims as $victim) {
            $bytesById[(int)$victim['elementId']] = (int)$victim['bytes'];
        }

        foreach (array_chunk($plan->elementIds(), self::DELETE_CHUNK) as $chunk) {
            // Re-check pins on every batch, not once at the start. A plan can sit on a
            // confirmation screen for minutes; a pin added in that window has to win.
            $protectedIds = array_flip($pins->pinnedElementIds($chunk));

            foreach ($this->loadRevisionElements($chunk) as $element) {
                $elementId = (int)$element->id;

                if (isset($protectedIds[$elementId])) {
                    $result->skippedCount++;
                    continue;
                }

                try {
                    if ($elementsService->deleteElement($element, $settings->hardDelete)) {
                        $result->deletedCount++;
                        $result->freedBytes += $bytesById[$elementId] ?? 0;
                    } else {
                        $result->skippedCount++;
                    }
                } catch (Throwable $e) {
                    $result->errors[] = sprintf('Revision element #%d: %s', $elementId, $e->getMessage());
                    Craft::error("Failed to prune revision element #$elementId: {$e->getMessage()}", Plugin::LOG_CATEGORY);
                }
            }
        }

        // Anything the plan named that no longer exists — deleted by someone else between the
        // preview and the confirmation — is a skip, not an error. It is also the drift the
        // ledger exists to make visible.
        $result->skippedCount = max(0, $result->plannedCount - $result->deletedCount - count($result->errors));

        $runs->finish($result->runId, $result);

        return $result;
    }

    /**
     * A plan covering exactly one element's history, for the sidebar panel and the console.
     */
    public function planForElement(ElementInterface $canonical, bool $purgeAll = false, ?int $limit = null): PrunePlan
    {
        return $this->resolve(new PruneScope([
            'scope' => PruneScope::ELEMENT,
            'canonicalId' => (int)$canonical->id,
            'purgeAll' => $purgeAll,
        ]), $limit);
    }

    /**
     * Canonical elements the scope covers that have more than one revision.
     *
     * `HAVING COUNT(*) > 1` is the cheap filter that keeps a site-wide prune from carrying
     * every entry on the site through the rest of the pipeline: an element with one revision
     * can never lose it, whatever the policy says, because `minKeep` never goes below one.
     * A purge-everything scope has no such floor, so it does not get the filter.
     *
     * @return array<int, array{elementType: string, sectionId: int|null, revisionCount: int}>
     */
    private function candidates(PruneScope $scope): array
    {
        $query = (new Query())
            ->select([
                'canonicalId' => 'r.canonicalId',
                'elementType' => 'ce.type',
                'sectionId' => 'en.sectionId',
                'revisionCount' => 'COUNT(*)',
            ])
            ->from(['r' => CraftTable::REVISIONS])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.revisionId]] = [[r.id]]')
            ->innerJoin(['ce' => CraftTable::ELEMENTS], '[[ce.id]] = [[r.canonicalId]]')
            ->leftJoin(['en' => CraftTable::ENTRIES], '[[en.id]] = [[r.canonicalId]]')
            ->where(['e.dateDeleted' => null])
            ->groupBy(['r.canonicalId', 'ce.type', 'en.sectionId']);

        if (!$scope->purgeAll) {
            $query->having(['>', 'COUNT(*)', 1]);
        }

        $this->applyScope($query, $scope);

        $rows = $query->all();
        $candidates = [];

        foreach ($rows as $row) {
            $candidates[(int)$row['canonicalId']] = [
                'elementType' => (string)$row['elementType'],
                'sectionId' => $row['sectionId'] !== null ? (int)$row['sectionId'] : null,
                'revisionCount' => (int)$row['revisionCount'],
            ];
        }

        return $candidates;
    }

    private function applyScope(Query $query, PruneScope $scope): void
    {
        switch ($scope->scope) {
            case PruneScope::ELEMENT:
                $query->andWhere(['r.canonicalId' => $scope->canonicalId]);
                break;

            case PruneScope::SECTION:
                $sectionId = $this->sectionIdFromUid($scope->sectionUid);
                // A scope naming a section that no longer exists must match nothing, not
                // everything — `null` in an `IN` clause would quietly widen the prune.
                $query->andWhere(['en.sectionId' => $sectionId ?? -1]);
                break;

            case PruneScope::ELEMENT_TYPE:
                $query->andWhere(['ce.type' => $scope->elementType]);
                break;
        }

        if ($scope->siteId !== null) {
            $query
                ->innerJoin(['rs' => Table::REVISION_SITES], '[[rs.revisionId]] = [[r.id]]')
                ->andWhere(['rs.siteId' => $scope->siteId]);
        }
    }

    /**
     * Every revision of the given canonical elements, newest first, with pin state.
     *
     * @param int[] $canonicalIds
     * @return array<int, array<int, array{elementId: int, revisionId: int, num: int, dateCreated: DateTime|null, pinned: bool}>>
     */
    private function revisionsFor(array $canonicalIds, PruneScope $scope): array
    {
        $query = (new Query())
            ->select([
                'canonicalId' => 'r.canonicalId',
                'revisionId' => 'r.id',
                'num' => 'r.num',
                'elementId' => 'e.id',
                'dateCreated' => 'e.dateCreated',
                'pinnedAt' => 'p.dateCreated',
            ])
            ->from(['r' => CraftTable::REVISIONS])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.revisionId]] = [[r.id]]')
            ->leftJoin(['p' => Table::PINS], '[[p.revisionId]] = [[r.id]]')
            ->where(['r.canonicalId' => $canonicalIds, 'e.dateDeleted' => null])
            ->orderBy(['r.canonicalId' => SORT_ASC, 'r.num' => SORT_DESC]);

        if ($scope->siteId !== null) {
            $query
                ->innerJoin(['rs' => Table::REVISION_SITES], '[[rs.revisionId]] = [[r.id]]')
                ->andWhere(['rs.siteId' => $scope->siteId]);
        }

        $grouped = [];

        foreach ($query->all() as $row) {
            $grouped[(int)$row['canonicalId']][] = [
                'elementId' => (int)$row['elementId'],
                'revisionId' => (int)$row['revisionId'],
                'num' => (int)$row['num'],
                'dateCreated' => $row['dateCreated'] !== null ? new DateTime((string)$row['dateCreated']) : null,
                'pinned' => $row['pinnedAt'] !== null,
            ];
        }

        return $grouped;
    }

    /**
     * @param int[] $elementIds
     * @return ElementInterface[]
     */
    private function loadRevisionElements(array $elementIds): array
    {
        $byType = (new Query())
            ->select(['id', 'type'])
            ->from(CraftTable::ELEMENTS)
            ->where(['id' => $elementIds])
            ->all();

        $grouped = [];

        foreach ($byType as $row) {
            $grouped[(string)$row['type']][] = (int)$row['id'];
        }

        $elements = [];

        foreach ($grouped as $elementType => $ids) {
            if (!class_exists($elementType)) {
                continue;
            }

            /** @var class-string<ElementInterface> $elementType */
            $found = $elementType::find()
                ->id($ids)
                ->revisions(true)
                ->status(null)
                ->trashed(null)
                ->site('*')
                ->unique()
                ->all();

            foreach ($found as $element) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    private function attachSizes(PrunePlan $plan): void
    {
        $ids = $plan->elementIds();

        if ($ids === []) {
            return;
        }

        $sizes = Plugin::getInstance()->revisions->sizesFor($ids);

        foreach ($plan->victims as $i => $victim) {
            $plan->victims[$i]['bytes'] = $sizes[(int)$victim['elementId']] ?? 0;
        }
    }

    private function attachTitles(PrunePlan $plan): void
    {
        $canonicalIds = array_keys($plan->countsByCanonical());

        if ($canonicalIds === []) {
            return;
        }

        $rows = (new Query())
            ->select(['elementId', 'title'])
            ->from(CraftTable::ELEMENTS_SITES)
            ->where(['elementId' => $canonicalIds])
            ->andWhere(['siteId' => Craft::$app->getSites()->getPrimarySite()->id])
            ->all();

        foreach ($rows as $row) {
            $plan->canonicalTitles[(int)$row['elementId']] = (string)($row['title'] ?? '');
        }
    }

    private function sectionIdFromUid(?string $uid): ?int
    {
        if ($uid === null) {
            return null;
        }

        return Craft::$app->getEntries()->getSectionByUid($uid)?->id;
    }

    /**
     * @return array<int, string>
     */
    private function sectionUidsById(): array
    {
        $uids = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            /** @var Section $section */
            $uids[(int)$section->id] = (string)$section->uid;
        }

        return $uids;
    }
}
