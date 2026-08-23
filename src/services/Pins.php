<?php

namespace justinholtweb\visorr\services;

use Craft;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\visorr\db\Table;
use justinholtweb\visorr\Plugin;
use yii\base\Component;

/**
 * Pinned revisions: "whatever else you throw away, keep this one".
 *
 * The feature exists because retention policy and editorial value have nothing to do with each
 * other. "Keep the last 20" is a storage decision; "keep the version we shipped the rebrand
 * with" is an editorial one, and any tool that only understands the first will eventually
 * delete the second.
 *
 * A pin is enforced by vetoing the deletion of the element itself, in
 * {@see Plugin::registerPinGuard()} — not by every prune remembering to check. That is the only
 * way it can also stop *Craft's* `PruneRevisions` job, which knows nothing about Visorr and runs
 * on its own schedule inside `createRevision()`.
 */
class Pins extends Component
{
    /**
     * Pin a revision. Idempotent: pinning a pinned revision updates its label.
     */
    public function pin(int $revisionId, ?string $label = null, ?int $userId = null): bool
    {
        if (!$this->revisionExists($revisionId)) {
            return false;
        }

        $now = Db::prepareDateForDb(new DateTime());
        $userId ??= Craft::$app->getUser()->getId();

        Craft::$app->getDb()->createCommand()->upsert(Table::PINS, [
            'revisionId' => $revisionId,
            'label' => $label,
            'pinnedBy' => $userId,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ], [
            'label' => $label,
            'pinnedBy' => $userId,
            'dateUpdated' => $now,
        ])->execute();

        return true;
    }

    public function unpin(int $revisionId): bool
    {
        return (bool)Db::delete(Table::PINS, ['revisionId' => $revisionId]);
    }

    public function toggle(int $revisionId, ?string $label = null): bool
    {
        if ($this->isPinned($revisionId)) {
            $this->unpin($revisionId);
            return false;
        }

        $this->pin($revisionId, $label);
        return true;
    }

    public function isPinned(int $revisionId): bool
    {
        return (new Query())
            ->from(Table::PINS)
            ->where(['revisionId' => $revisionId])
            ->exists();
    }

    /**
     * Which of these revision IDs are pinned.
     *
     * @param int[] $revisionIds
     * @return int[]
     */
    public function pinnedAmong(array $revisionIds): array
    {
        if ($revisionIds === []) {
            return [];
        }

        return array_map('intval', (new Query())
            ->select(['revisionId'])
            ->from(Table::PINS)
            ->where(['revisionId' => $revisionIds])
            ->column());
    }

    /**
     * The revision *element* IDs that are pinned, among the given element IDs.
     *
     * Pruning works in element IDs — that is what gets deleted — while pins are keyed on the
     * `revisions` row. This is where the two are reconciled, once, rather than in every caller.
     *
     * @param int[] $elementIds
     * @return int[]
     */
    public function pinnedElementIds(array $elementIds): array
    {
        if ($elementIds === []) {
            return [];
        }

        return array_map('intval', (new Query())
            ->select(['e.id'])
            ->from(['e' => CraftTable::ELEMENTS])
            ->innerJoin(['p' => Table::PINS], '[[p.revisionId]] = [[e.revisionId]]')
            ->where(['e.id' => $elementIds])
            ->column());
    }

    /**
     * Whether a given revision *element* is protected from deletion right now.
     *
     * Consulted by the guard on every element deletion, so it is deliberately one indexed
     * query and nothing else — and it answers `false` immediately when protection is off,
     * before touching the database at all.
     */
    public function protects(int $elementId): bool
    {
        if (!Plugin::getInstance()->getSettings()->protectPins) {
            return false;
        }

        return (new Query())
            ->from(['e' => CraftTable::ELEMENTS])
            ->innerJoin(['p' => Table::PINS], '[[p.revisionId]] = [[e.revisionId]]')
            ->where(['e.id' => $elementId])
            ->exists();
    }

    /**
     * Every pinned revision on the site, newest first — the "what am I keeping forever?" list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(?int $limit = null): array
    {
        return (new Query())
            ->select([
                'revisionId' => 'p.revisionId',
                'label' => 'p.label',
                'pinnedBy' => 'p.pinnedBy',
                'datePinned' => 'p.dateCreated',
                'canonicalId' => 'r.canonicalId',
                'num' => 'r.num',
                'elementId' => 'e.id',
                'elementType' => 'e.type',
            ])
            ->from(['p' => Table::PINS])
            ->innerJoin(['r' => CraftTable::REVISIONS], '[[r.id]] = [[p.revisionId]]')
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.revisionId]] = [[r.id]]')
            ->orderBy(['p.dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function count(): int
    {
        return (int)(new Query())->from(Table::PINS)->count();
    }

    private function revisionExists(int $revisionId): bool
    {
        return (new Query())
            ->from(CraftTable::REVISIONS)
            ->where(['id' => $revisionId])
            ->exists();
    }
}
