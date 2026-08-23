<?php

namespace justinholtweb\visorr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\visorr\models\PruneScope;
use justinholtweb\visorr\Plugin;
use yii\console\ExitCode;

/**
 * Prune revisions from the command line.
 *
 * This is how a big site keeps its history bounded from CI or cron. `plan` never writes
 * anything; `apply` writes only what `plan` would have named, because they call the same
 * resolver.
 */
class PruneController extends Controller
{
    /** @var string What to prune: `all`, `section`, `elementType` or `element`. */
    public string $scope = 'all';

    /** @var string|null Section handle, for `--scope=section`. */
    public ?string $section = null;

    /** @var string|null Element class, for `--scope=elementType`. */
    public ?string $elementType = null;

    /** @var int|null Canonical element ID, for `--scope=element`. */
    public ?int $element = null;

    /** @var string|null Site handle, to restrict to revisions authored from one site. */
    public ?string $site = null;

    /** @var bool Ignore retention policy and remove everything unpinned. */
    public bool $purge = false;

    /** @var int|null Override the configured batch size. */
    public ?int $limit = null;

    /** @var bool Skip the confirmation prompt. Required for unattended runs. */
    public bool $force = false;

    /** @var bool Only act if the schedule says a prune is due. */
    public bool $due = false;

    public function options($actionID): array
    {
        $base = parent::options($actionID);

        return match ($actionID) {
            'plan' => array_merge($base, ['scope', 'section', 'elementType', 'element', 'site', 'purge', 'limit']),
            'apply' => array_merge($base, ['scope', 'section', 'elementType', 'element', 'site', 'purge', 'limit', 'force', 'due']),
            default => $base,
        };
    }

    /**
     * Show what a prune would delete. Writes nothing.
     */
    public function actionPlan(): int
    {
        $scope = $this->buildScope();

        if ($scope === null) {
            return ExitCode::USAGE;
        }

        $plan = Plugin::getInstance()->pruning->resolve($scope, $this->limit ?? PHP_INT_MAX);

        $this->printPlan($plan);

        return ExitCode::OK;
    }

    /**
     * Delete the revisions the plan names.
     */
    public function actionApply(): int
    {
        $plugin = Plugin::getInstance();

        if ($this->due && !$plugin->schedules->isDue()) {
            $this->stdout("No prune is due.\n");
            return ExitCode::OK;
        }

        $scope = $this->buildScope();

        if ($scope === null) {
            return ExitCode::USAGE;
        }

        $plan = $plugin->pruning->resolve($scope, $this->limit);

        $this->printPlan($plan);

        if ($plan->count() === 0) {
            return ExitCode::OK;
        }

        if (!$this->force && !$this->confirm("Delete {$plan->count()} revisions?")) {
            $this->stdout("Nothing was deleted.\n");
            return ExitCode::OK;
        }

        $result = $plugin->pruning->apply($plan, 'console');

        $this->stdout(sprintf(
            "Deleted %d of %d revisions, reclaiming %s.\n",
            $result->deletedCount,
            $result->plannedCount,
            $plugin->storage->formatBytes($result->freedBytes),
        ), Console::FG_GREEN);

        if ($result->skippedCount > 0) {
            $this->stdout("{$result->skippedCount} were skipped — pinned or already gone.\n", Console::FG_YELLOW);
        }

        foreach ($result->errors as $error) {
            $this->stderr("$error\n", Console::FG_RED);
        }

        if ($plan->truncated) {
            $this->stdout("Batch limit reached — run again to continue.\n", Console::FG_YELLOW);
        }

        return $result->errors === [] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    private function buildScope(): ?PruneScope
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro() && $this->scope !== PruneScope::ELEMENT) {
            $this->stderr("Pruning by section, element type or site-wide requires Visorr Pro.\n", Console::FG_RED);
            return null;
        }

        $sectionUid = null;

        if ($this->section !== null) {
            $section = \Craft::$app->getEntries()->getSectionByHandle($this->section);

            if ($section === null) {
                $this->stderr("Unknown section: {$this->section}\n", Console::FG_RED);
                return null;
            }

            $sectionUid = $section->uid;
        }

        $siteId = null;

        if ($this->site !== null) {
            $site = \Craft::$app->getSites()->getSiteByHandle($this->site);

            if ($site === null) {
                $this->stderr("Unknown site: {$this->site}\n", Console::FG_RED);
                return null;
            }

            $siteId = (int)$site->id;
        }

        $scope = new PruneScope([
            'scope' => $this->scope,
            'sectionUid' => $sectionUid,
            'elementType' => $this->elementType,
            'canonicalId' => $this->element,
            'siteId' => $siteId,
            'purgeAll' => $this->purge,
        ]);

        if (!$scope->validate()) {
            $this->stderr(implode("\n", $scope->getErrorSummary(true)) . "\n", Console::FG_RED);
            return null;
        }

        return $scope;
    }

    private function printPlan(\justinholtweb\visorr\models\PrunePlan $plan): void
    {
        $storage = Plugin::getInstance()->storage;

        $this->stdout(sprintf(
            "Scope: %s\nScanned: %d elements\nWould delete: %d revisions (%s)\nPinned, kept: %d\n",
            $plan->scope?->describe() ?? '—',
            $plan->elementsScanned,
            $plan->count(),
            $storage->formatBytes($plan->bytes()),
            $plan->protectedCount,
        ));

        if ($plan->truncated) {
            $this->stdout("This is one batch; there is more to remove.\n", Console::FG_YELLOW);
        }

        $counts = $plan->countsByCanonical();

        if ($counts === []) {
            return;
        }

        $this->stdout("\n");
        $this->stdout(sprintf("%-10s  %-8s  %s\n", 'ELEMENT', 'GOING', 'TITLE'));

        foreach (array_slice($counts, 0, 25, true) as $canonicalId => $count) {
            $this->stdout(sprintf(
                "%-10d  %-8d  %s\n",
                $canonicalId,
                $count,
                $plan->canonicalTitles[$canonicalId] ?? '—',
            ));
        }

        if (count($counts) > 25) {
            $this->stdout(sprintf("…and %d more elements\n", count($counts) - 25));
        }

        $this->stdout("\n");
    }
}
