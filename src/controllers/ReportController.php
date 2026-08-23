<?php

namespace justinholtweb\visorr\controllers;

use Craft;
use justinholtweb\visorr\Plugin;
use yii\web\Response;

/**
 * The storage report — where the history is and what it costs.
 */
class ReportController extends BaseController
{
    public function actionIndex(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);
        $this->requirePro('storageReport');

        $storage = $this->plugin()->storage;

        return $this->asCpScreen()
            ->title(Craft::t('visorr', 'Revision storage'))
            ->selectedSubnavItem('report')
            ->contentTemplate('visorr/report/index', [
                'overview' => $storage->overview(),
                'sections' => $storage->bySection(),
                'elementTypes' => $storage->byElementType(),
                'topElements' => $storage->topElements(25),
                'storage' => $storage,
                'canPrune' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_PRUNE),
            ]);
    }
}
