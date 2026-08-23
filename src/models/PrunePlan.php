<?php

namespace justinholtweb\visorr\models;

use craft\base\Model;

/**
 * Exactly what a prune would delete.
 *
 * The plan is the dry run *and* the input to the execution — the same object, resolved once.
 * A preview that builds its own query is a preview of a different deletion; this family learnt
 * that building Nuke and the invariant carries over unchanged.
 */
class PrunePlan extends Model
{
    public ?PruneScope $scope = null;

    /**
     * @var array<int, array{elementId: int, revisionId: int, canonicalId: int, num: int, bytes: int, dateCreated: string|null, reason: string}>
     *   One row per revision that would go, in the order they would be deleted.
     */
    public array $victims = [];

    /** @var array<int, string> canonical element ID => its title, for the preview table. */
    public array $canonicalTitles = [];

    /** @var int Revisions inside the scope that a pin saved. */
    public int $protectedCount = 0;

    /** @var int Canonical elements the scope touched. */
    public int $elementsScanned = 0;

    /**
     * @var bool Whether the batch size cut the plan short. A truncated plan is not a smaller
     * problem, it is the same problem seen through a window — the screen says so, and the next
     * run picks up where this one stopped.
     */
    public bool $truncated = false;

    public function count(): int
    {
        return count($this->victims);
    }

    public function bytes(): int
    {
        return array_sum(array_column($this->victims, 'bytes'));
    }

    /**
     * @return int[]
     */
    public function elementIds(): array
    {
        return array_map('intval', array_column($this->victims, 'elementId'));
    }

    /**
     * How many revisions would go, per canonical element — the shape the preview table wants.
     *
     * @return array<int, int>
     */
    public function countsByCanonical(): array
    {
        $counts = [];

        foreach ($this->victims as $victim) {
            $canonicalId = (int)$victim['canonicalId'];
            $counts[$canonicalId] = ($counts[$canonicalId] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }
}
