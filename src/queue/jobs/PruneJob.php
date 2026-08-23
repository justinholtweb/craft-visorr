<?php

namespace justinholtweb\visorr\queue\jobs;

use Craft;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use justinholtweb\visorr\models\PruneScope;
use justinholtweb\visorr\Plugin;

/**
 * Runs a prune in the background.
 *
 * The job carries the *scope*, not the resolved plan. A plan that sat in a queue for an hour
 * would be a list of element IDs that may since have been pinned, edited or deleted; resolving
 * at execution time means the job deletes what the policy says now, not what it said when
 * somebody clicked a button.
 *
 * That is the one place the "one resolver for preview and execution" rule is deliberately bent,
 * and it is bent in the safe direction: the scheduled prune has no preview to be faithful to.
 * The control-panel path, where a human has read a preview and approved it, still applies
 * exactly the plan they saw.
 */
class PruneJob extends BaseJob
{
    /** @var array<string, mixed> A serialized {@see PruneScope}. */
    public array $scopeConfig = [];

    /** @var string What set this off — 'schedule', 'console', 'cp'. */
    public string $trigger = 'schedule';

    /** @var int|null Overrides the configured batch size. */
    public ?int $limit = null;

    /** @var int|null Attributed to this user in the ledger. */
    public ?int $userId = null;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $scope = new PruneScope($this->scopeConfig);

        $this->setProgress($queue, 0, Translation::prep('visorr', 'Working out what to prune'));

        $plan = $plugin->pruning->resolve($scope, $this->limit);

        if ($plan->count() === 0) {
            return;
        }

        $this->setProgress($queue, 0.2, Translation::prep('visorr', 'Removing {count} revisions', [
            'count' => $plan->count(),
        ]));

        $result = $plugin->pruning->apply($plan, $this->trigger, $this->userId);

        if ($result->errors !== []) {
            Craft::warning(
                sprintf('Visorr prune finished with %d errors: %s', count($result->errors), implode('; ', $result->errors)),
                Plugin::LOG_CATEGORY
            );
        }

        // A truncated plan means the batch size cut it short, so there is more to do. Queueing
        // the follow-up here rather than looping keeps each job bounded and interruptible.
        if ($plan->truncated) {
            Craft::$app->getQueue()->push(new self([
                'scopeConfig' => $this->scopeConfig,
                'trigger' => $this->trigger,
                'limit' => $this->limit,
                'userId' => $this->userId,
            ]));
        }
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('visorr', 'Pruning revisions');
    }
}
