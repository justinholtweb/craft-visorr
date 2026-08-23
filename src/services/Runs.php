<?php

namespace justinholtweb\visorr\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\visorr\db\Table;
use justinholtweb\visorr\models\PrunePlan;
use justinholtweb\visorr\models\PruneResult;
use justinholtweb\visorr\models\PruneScope;
use yii\base\Component;

/**
 * The prune ledger: what was proposed, what happened, and when.
 *
 * The planned IDs are stored verbatim alongside the outcome. That is the point of the table —
 * not "we deleted 412 revisions", which nobody can check, but "we intended to delete these 412
 * and here is what became of them". Drift between the two is the interesting signal, and a
 * ledger that recorded only the result would have thrown it away.
 */
class Runs extends Component
{
    /**
     * Open a ledger row for a prune that is about to run.
     *
     * @return int|null The row ID, or null if the ledger could not be written — which must not
     *   stop the prune. A missing audit row is a smaller problem than a site that cannot
     *   reclaim its disk.
     */
    public function start(PrunePlan $plan, string $trigger = 'cp', ?int $userId = null): ?int
    {
        $scope = $plan->scope ?? new PruneScope();
        $now = Db::prepareDateForDb(new DateTime());
        $userId ??= Craft::$app->getUser()->getId();

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::PRUNES, [
                'scope' => $scope->scope,
                'elementType' => $scope->elementType,
                'sectionUid' => $scope->sectionUid,
                'canonicalId' => $scope->canonicalId,
                'siteId' => $scope->siteId,
                'applied' => true,
                'plannedCount' => $plan->count(),
                'protectedCount' => $plan->protectedCount,
                'plannedIds' => json_encode($plan->elementIds()),
                'trigger' => $trigger,
                'triggeredBy' => $userId,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            return (int)Craft::$app->getDb()->getLastInsertID(Table::PRUNES);
        } catch (\Throwable $e) {
            Craft::warning("Could not open a Visorr prune ledger row: {$e->getMessage()}", 'visorr');
            return null;
        }
    }

    /**
     * Close a ledger row with what actually happened.
     */
    public function finish(?int $runId, PruneResult $result): void
    {
        if ($runId === null) {
            return;
        }

        try {
            Db::update(Table::PRUNES, [
                'deletedCount' => $result->deletedCount,
                'freedBytes' => $result->freedBytes,
                'errors' => $result->errors !== [] ? json_encode($result->errors) : null,
                'dateFinished' => Db::prepareDateForDb(new DateTime()),
            ], ['id' => $runId]);
        } catch (\Throwable $e) {
            Craft::warning("Could not close Visorr prune ledger row #$runId: {$e->getMessage()}", 'visorr');
        }
    }

    /**
     * Record a dry run, so "we looked and this is what we would have done" is also history.
     */
    public function recordDryRun(PrunePlan $plan, ?int $userId = null): ?int
    {
        $scope = $plan->scope ?? new PruneScope();
        $now = Db::prepareDateForDb(new DateTime());

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::PRUNES, [
                'scope' => $scope->scope,
                'elementType' => $scope->elementType,
                'sectionUid' => $scope->sectionUid,
                'canonicalId' => $scope->canonicalId,
                'siteId' => $scope->siteId,
                'applied' => false,
                'plannedCount' => $plan->count(),
                'protectedCount' => $plan->protectedCount,
                'freedBytes' => $plan->bytes(),
                'plannedIds' => json_encode($plan->elementIds()),
                'trigger' => 'dry-run',
                'triggeredBy' => $userId ?? Craft::$app->getUser()->getId(),
                'dateFinished' => $now,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            return (int)Craft::$app->getDb()->getLastInsertID(Table::PRUNES);
        } catch (\Throwable $e) {
            Craft::warning("Could not record a Visorr dry run: {$e->getMessage()}", 'visorr');
            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 50, bool $appliedOnly = true): array
    {
        $query = (new Query())
            ->from(Table::PRUNES)
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit);

        if ($appliedOnly) {
            $query->where(['applied' => true]);
        }

        return $query->all();
    }

    public function find(int $id): ?array
    {
        $row = (new Query())
            ->from(Table::PRUNES)
            ->where(['id' => $id])
            ->one();

        return $row === false || $row === null ? null : $row;
    }

    /**
     * When the last applied prune finished. The schedule reads this to decide whether one is due.
     */
    public function lastAppliedAt(): ?DateTime
    {
        $value = (new Query())
            ->select(['dateFinished'])
            ->from(Table::PRUNES)
            ->where(['applied' => true])
            ->andWhere(['not', ['dateFinished' => null]])
            ->orderBy(['dateFinished' => SORT_DESC])
            ->scalar();

        return is_string($value) && $value !== '' ? new DateTime($value) : null;
    }

    /**
     * Total revisions Visorr has removed, and bytes reclaimed — the headline on the dashboard.
     *
     * @return array{runs: int, deleted: int, bytes: int}
     */
    public function totals(): array
    {
        $row = (new Query())
            ->select([
                'runs' => 'COUNT(*)',
                'deleted' => 'COALESCE(SUM([[deletedCount]]), 0)',
                'bytes' => 'COALESCE(SUM([[freedBytes]]), 0)',
            ])
            ->from(Table::PRUNES)
            ->where(['applied' => true])
            ->one();

        return [
            'runs' => (int)($row['runs'] ?? 0),
            'deleted' => (int)($row['deleted'] ?? 0),
            'bytes' => (int)($row['bytes'] ?? 0),
        ];
    }
}
