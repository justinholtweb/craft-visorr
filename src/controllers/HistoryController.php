<?php

namespace justinholtweb\visorr\controllers;

use Craft;
use justinholtweb\visorr\models\PruneScope;
use justinholtweb\visorr\Plugin;
use yii\web\Response;

/**
 * One element's full revision history: every revision with its author, notes, size and pin
 * state, and the two actions that belong to a single element — pin, and purge.
 *
 * Craft has a screen like this. It has four columns and no verbs.
 */
class HistoryController extends BaseController
{
    public function actionIndex(int $elementId): Response
    {
        $request = Craft::$app->getRequest();
        $siteId = $request->getParam('siteId');
        $canonical = $this->canonical($elementId, $siteId !== null ? (int)$siteId : null);

        $this->requireHistoryAccess($canonical);

        $plugin = $this->plugin();
        $siteFiltered = $plugin->siteTracking->applies($canonical);

        $infos = $plugin->revisions->getRevisionInfos(
            $canonical,
            $siteFiltered ? $canonical->siteId : null,
            null,
            true,
        );

        return $this->asCpScreen()
            ->title(Craft::t('visorr', 'History of “{title}”', ['title' => $canonical->getUiLabel()]))
            ->crumbs([
                ['label' => Craft::t('visorr', 'Visorr'), 'url' => 'visorr'],
                ['label' => (string)$canonical->getUiLabel(), 'url' => (string)$canonical->getCpEditUrl()],
                ['label' => Craft::t('visorr', 'History'), 'current' => true],
            ])
            ->contentTemplate('visorr/revisions/index', [
                'element' => $canonical,
                'infos' => $infos,
                'retention' => $plugin->retention->forElement($canonical),
                'siteFiltered' => $siteFiltered,
                'siteCounts' => $siteFiltered ? $plugin->siteTracking->countsBySite((int)$canonical->id) : [],
                'canPin' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_PIN),
                'canPrune' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_PRUNE),
                'totalBytes' => array_sum(array_map(fn($info) => $info->sizeBytes, $infos)),
            ]);
    }

    /**
     * Delete specific revisions of one element.
     *
     * Named revisions rather than a policy: this is the "I know exactly which one has to go"
     * path, and it still runs through {@see \justinholtweb\visorr\services\Pruning::apply()} so
     * it lands in the same ledger as everything else.
     */
    public function actionDeleteRevisions(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_PRUNE);

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $revisionIds = array_map('intval', (array)$request->getBodyParam('revisionIds', []));

        $canonical = $this->canonical($elementId);
        $this->requireHistoryAccess($canonical);

        if ($revisionIds === []) {
            return $this->asFailure(Craft::t('visorr', 'No revisions were selected.'));
        }

        $plugin = $this->plugin();

        // Resolve the whole element's history, then keep only what was asked for. Going through
        // the resolver rather than deleting the posted IDs directly is what makes a pin still
        // win here: a pinned revision is never in the plan, whatever the form said.
        $plan = $plugin->pruning->planForElement($canonical, true, PHP_INT_MAX);
        $wanted = array_flip($revisionIds);

        $plan->victims = array_values(array_filter(
            $plan->victims,
            fn(array $victim) => isset($wanted[(int)$victim['revisionId']])
        ));

        if ($plan->count() === 0) {
            return $this->asFailure(Craft::t('visorr', 'None of those revisions can be deleted — they may be pinned.'));
        }

        $result = $plugin->pruning->apply($plan, 'cp');

        return $this->asSuccess(Craft::t('visorr', '{count} revisions deleted.', [
            'count' => $result->deletedCount,
        ]));
    }

    /**
     * Purge everything but the pinned revisions for one element.
     */
    public function actionPurge(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_PRUNE);

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $canonical = $this->canonical($elementId);

        $this->requireHistoryAccess($canonical);

        $plugin = $this->plugin();
        $plan = $plugin->pruning->resolve(new PruneScope([
            'scope' => PruneScope::ELEMENT,
            'canonicalId' => (int)$canonical->id,
            'purgeAll' => true,
        ]), PHP_INT_MAX);

        if (!$request->getBodyParam('confirm')) {
            return $this->asJson([
                'count' => $plan->count(),
                'protected' => $plan->protectedCount,
                'bytes' => $plan->bytes(),
            ]);
        }

        $result = $plugin->pruning->apply($plan, 'cp');

        return $this->asSuccess(Craft::t('visorr', '{count} revisions deleted, {protected} kept.', [
            'count' => $result->deletedCount,
            'protected' => $plan->protectedCount,
        ]));
    }
}
