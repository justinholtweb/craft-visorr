<?php

namespace justinholtweb\visorr\controllers;

use Craft;
use justinholtweb\visorr\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The prune ledger, as a screen.
 */
class RunsController extends BaseController
{
    public function actionIndex(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        $showDryRuns = (bool)Craft::$app->getRequest()->getParam('dryRuns');

        return $this->asCpScreen()
            ->title(Craft::t('visorr', 'Prune history'))
            ->selectedSubnavItem('runs')
            ->contentTemplate('visorr/prune/runs', [
                'runs' => $this->plugin()->runs->recent(100, !$showDryRuns),
                'showDryRuns' => $showDryRuns,
                'totals' => $this->plugin()->runs->totals(),
                'storage' => $this->plugin()->storage,
            ]);
    }

    public function actionDetail(int $id): Response
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        $run = $this->plugin()->runs->find($id);

        if ($run === null) {
            throw new NotFoundHttpException("No prune run with ID $id.");
        }

        $plannedIds = json_decode((string)($run['plannedIds'] ?? '[]'), true) ?: [];
        $errors = json_decode((string)($run['errors'] ?? '[]'), true) ?: [];

        return $this->asCpScreen()
            ->title(Craft::t('visorr', 'Prune run #{id}', ['id' => $id]))
            ->crumbs([
                ['label' => Craft::t('visorr', 'Prune history'), 'url' => 'visorr/runs'],
                ['label' => "#$id", 'current' => true],
            ])
            ->contentTemplate('visorr/prune/run', [
                'run' => $run,
                'plannedIds' => $plannedIds,
                'errors' => is_array($errors) ? $errors : [],
                'storage' => $this->plugin()->storage,
            ]);
    }
}
