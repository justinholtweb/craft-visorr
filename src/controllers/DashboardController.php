<?php

namespace justinholtweb\visorr\controllers;

use Craft;
use justinholtweb\visorr\Plugin;
use yii\web\Response;

/**
 * The overview: how much history exists, what governs it, and what has been done to it.
 */
class DashboardController extends BaseController
{
    public function actionIndex(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        $plugin = $this->plugin();

        return $this->asCpScreen()
            ->title(Craft::t('visorr', 'Visorr'))
            ->selectedSubnavItem('dashboard')
            ->contentTemplate('visorr/dashboard', [
                'overview' => $plugin->storage->overview(),
                'totals' => $plugin->runs->totals(),
                'recentRuns' => $plugin->runs->recent(5),
                'policies' => $plugin->retention->allPolicies(),
                'craftMax' => Craft::$app->getConfig()->getGeneral()->maxRevisions,
                'isPro' => $plugin->isPro(),
                'storage' => $plugin->storage,
                'nextDue' => $plugin->schedules->nextDueAt(),
                'settings' => $plugin->getSettings(),
                'topElements' => $plugin->isPro() ? $plugin->storage->topElements(5) : [],
            ]);
    }
}
