<?php

namespace justinholtweb\visorr\models;

use craft\base\Model;

/**
 * What a prune actually did, as opposed to what it planned to do.
 *
 * The two are stored side by side on the ledger row on purpose. They should match; when they
 * do not — a pin added between the preview and the confirmation, an element deleted by someone
 * else in the meantime — the gap is the interesting part, and a result that only recorded the
 * outcome would have thrown it away.
 */
class PruneResult extends Model
{
    public int $plannedCount = 0;
    public int $deletedCount = 0;
    public int $skippedCount = 0;
    public int $freedBytes = 0;

    /** @var string[] */
    public array $errors = [];

    /** @var int|null The ledger row this run was recorded as. */
    public ?int $runId = null;

    public function matchedPlan(): bool
    {
        return $this->deletedCount === $this->plannedCount && $this->errors === [];
    }
}
