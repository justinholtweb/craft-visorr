<?php

namespace justinholtweb\visorr\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\visorr\Plugin;
use yii\console\ExitCode;

/**
 * Per-site revision tracking, from the command line.
 *
 * The backfill exists because there is no way to know which site a pre-Visorr revision was
 * authored from — that information was never recorded. Rather than guess, this assigns
 * untracked revisions to a site you name. An administrator who knows their own history can say
 * "all of these were ours"; everyone else should leave them alone and let them keep showing on
 * the primary site.
 */
class SitesController extends Controller
{
    /** @var string Site handle to assign untracked revisions to. */
    public string $site = '';

    /** @var int|null Restrict to one canonical element. */
    public ?int $element = null;

    /** @var bool Actually write. Without it, this reports and stops. */
    public bool $apply = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'backfill' => ['site', 'element', 'apply'],
            default => [],
        });
    }

    /**
     * How many revisions have a recorded authoring site, and how many do not.
     */
    public function actionStatus(): int
    {
        $counts = (new \craft\db\Query())
            ->select([
                'total' => 'COUNT(*)',
            ])
            ->from(['r' => \craft\db\Table::REVISIONS])
            ->scalar();

        $tracked = (new \craft\db\Query())
            ->from(\justinholtweb\visorr\db\Table::REVISION_SITES)
            ->count();

        $this->stdout(sprintf(
            "%s revisions, %s with a recorded site, %s without.\n",
            number_format((int)$counts),
            number_format((int)$tracked),
            number_format((int)$counts - (int)$tracked),
        ));

        return ExitCode::OK;
    }

    /**
     * Assign untracked revisions to a site.
     */
    public function actionBackfill(): int
    {
        if ($this->site === '') {
            $this->stderr("--site is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $site = Craft::$app->getSites()->getSiteByHandle($this->site);

        if ($site === null) {
            $this->stderr("Unknown site: {$this->site}\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if (!$this->apply) {
            $this->stdout("Dry run. Re-run with --apply to write.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $written = Plugin::getInstance()->siteTracking->backfill((int)$site->id, $this->element);

        $this->stdout("Assigned $written revisions to {$site->name}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
