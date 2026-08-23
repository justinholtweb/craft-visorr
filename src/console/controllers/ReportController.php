<?php

namespace justinholtweb\visorr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\visorr\Plugin;
use yii\console\ExitCode;

/**
 * The storage report, for terminals and CI logs.
 */
class ReportController extends Controller
{
    /** @var int How many of the heaviest elements to list. */
    public int $top = 15;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'index' ? ['top'] : []);
    }

    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            $this->stderr("The storage report requires Visorr Pro.\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }

        $storage = $plugin->storage;
        $overview = $storage->overview();

        $this->stdout("Revision storage\n", Console::BOLD);
        $this->stdout(sprintf(
            "  %s revisions across %s elements, about %s.\n  %s pinned. Largest single revision: %s.\n\n",
            number_format($overview['revisions']),
            number_format($overview['elements']),
            $storage->formatBytes($overview['bytes']),
            number_format($overview['pinned']),
            $storage->formatBytes($overview['largest']),
        ));

        $this->stdout("By section\n", Console::BOLD);
        $this->stdout(sprintf("%-28s %10s %10s %12s\n", 'SECTION', 'ELEMENTS', 'REVISIONS', 'STORAGE'));

        foreach ($storage->bySection() as $row) {
            $this->stdout(sprintf(
                "%-28s %10s %10s %12s\n",
                mb_strimwidth($row['name'], 0, 28),
                number_format($row['elements']),
                number_format($row['revisions']),
                $storage->formatBytes($row['bytes']),
            ));
        }

        $this->stdout("\nHeaviest elements\n", Console::BOLD);
        $this->stdout(sprintf("%-10s %10s %12s  %s\n", 'ID', 'REVISIONS', 'STORAGE', 'TITLE'));

        foreach ($storage->topElements($this->top) as $row) {
            $this->stdout(sprintf(
                "%-10d %10s %12s  %s\n",
                $row['canonicalId'],
                number_format($row['revisions']),
                $storage->formatBytes($row['bytes']),
                mb_strimwidth((string)$row['title'], 0, 50),
            ));
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }
}
