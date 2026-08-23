<?php

namespace justinholtweb\visorr\controllers;

use Craft;
use justinholtweb\visorr\Plugin;
use yii\web\Response;

/**
 * Pinning: the editorial override on top of a storage policy.
 */
class PinsController extends BaseController
{
    public function actionIndex(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        $plugin = $this->plugin();
        $rows = $plugin->pins->all(200);
        $titles = [];

        foreach ($rows as $row) {
            $canonicalId = (int)$row['canonicalId'];

            if (!isset($titles[$canonicalId])) {
                // A pin can outlive the thing it was pinned to — the element is deleted, its
                // revisions linger until garbage collection, and the pin row goes with them.
                // Meanwhile this screen has to render.
                $element = $plugin->revisions->canonicalFor($canonicalId);

                try {
                    $titles[$canonicalId] = [
                        'label' => $element?->getUiLabel() ?? Craft::t('visorr', 'Deleted element'),
                        'url' => $element?->getCpEditUrl(),
                    ];
                } catch (\Throwable) {
                    $titles[$canonicalId] = [
                        'label' => Craft::t('visorr', 'Deleted element'),
                        'url' => null,
                    ];
                }
            }
        }

        return $this->asCpScreen()
            ->title(Craft::t('visorr', 'Pinned revisions'))
            ->selectedSubnavItem('pins')
            ->contentTemplate('visorr/revisions/pins', [
                'rows' => $rows,
                'titles' => $titles,
                'canPin' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_PIN),
            ]);
    }

    public function actionToggle(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_PIN);

        $request = Craft::$app->getRequest();
        $revisionId = (int)$request->getRequiredBodyParam('revisionId');
        $label = $request->getBodyParam('label') ?: null;

        $pinned = $this->plugin()->pins->toggle($revisionId, $label !== null ? (string)$label : null);

        return $this->asJson([
            'success' => true,
            'pinned' => $pinned,
            'message' => $pinned
                ? Craft::t('visorr', 'Revision pinned — pruning will leave it alone.')
                : Craft::t('visorr', 'Revision unpinned.'),
        ]);
    }
}
