<?php

namespace justinholtweb\visorr\services;

use Craft;
use craft\helpers\Queue as QueueHelper;
use DateInterval;
use DateTime;
use justinholtweb\visorr\models\PruneScope;
use justinholtweb\visorr\models\Settings;
use justinholtweb\visorr\Plugin;
use justinholtweb\visorr\queue\jobs\PruneJob;
use yii\base\Component;

/**
 * Decides when the recurring prune is due, and queues it.
 *
 * Two triggers, because sites differ. A real cron calling `visorr/prune/apply --due` is the
 * right answer and the default. Control-panel traffic is the fallback for sites that have no
 * cron at all — it never deletes anything inside a page request, it only queues a job, and the
 * due check is a single indexed read on the requests where nothing is due.
 *
 * "Due" is measured from the last *applied* prune, not from a stored next-run stamp. A stamp
 * has to be kept in step with a setting that can change under it; a timestamp of what actually
 * happened cannot drift.
 */
class Schedules extends Component
{
    /**
     * A mutex-free guard against two control-panel requests queueing the same prune at once.
     * The cache entry is set before the job is pushed and outlives the push by a minute, which
     * is far longer than the window between two page loads racing each other.
     */
    private const QUEUE_GUARD_KEY = 'visorr:prune-queued';

    public function isDue(): bool
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->scheduleEnabled || !Plugin::getInstance()->isPro()) {
            return false;
        }

        $next = $this->nextDueAt();

        return $next === null || $next <= new DateTime();
    }

    /**
     * When the next prune is due, or null if one has never run (in which case: now).
     */
    public function nextDueAt(): ?DateTime
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $last = Plugin::getInstance()->runs->lastAppliedAt();

        if ($last === null) {
            return null;
        }

        return (clone $last)->add(new DateInterval("PT{$settings->scheduleIntervalHours}H"));
    }

    /**
     * Queue a site-wide prune if one is due. Returns whether a job was pushed.
     */
    public function queueIfDue(): bool
    {
        if (!$this->isDue()) {
            return false;
        }

        $cache = Craft::$app->getCache();

        if ($cache->get(self::QUEUE_GUARD_KEY) !== false) {
            return false;
        }

        $cache->set(self::QUEUE_GUARD_KEY, true, 60);

        QueueHelper::push(new PruneJob([
            'scopeConfig' => (new PruneScope(['scope' => PruneScope::ALL]))->toArray(),
            'trigger' => 'schedule',
        ]));

        return true;
    }
}
