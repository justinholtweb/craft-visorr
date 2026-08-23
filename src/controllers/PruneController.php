<?php

namespace justinholtweb\visorr\controllers;

use Craft;
use craft\helpers\Queue as QueueHelper;
use justinholtweb\visorr\models\PruneScope;
use justinholtweb\visorr\Plugin;
use justinholtweb\visorr\queue\jobs\PruneJob;
use yii\web\Response;

/**
 * The prune screen: choose a scope, read exactly what would go, then apply it.
 *
 * The preview and the execution are the same resolution. The screen posts the plan back rather
 * than re-deriving it, and the apply step re-checks pins per batch — so the only ways the
 * outcome can differ from the preview are things that genuinely changed in between, and those
 * end up in the ledger as drift rather than being quietly absorbed.
 */
class PruneController extends BaseController
{
    public function actionIndex(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_PRUNE);

        $plugin = $this->plugin();

        return $this->asCpScreen()
            ->title(Craft::t('visorr', 'Prune revisions'))
            ->selectedSubnavItem('prune')
            ->contentTemplate('visorr/prune/index', [
                'sections' => Craft::$app->getEntries()->getAllSections(),
                'elementTypes' => $this->revisionableElementTypes(),
                'sites' => Craft::$app->getSites()->getAllSites(),
                'isPro' => $plugin->isPro(),
                'settings' => $plugin->getSettings(),
                'lastRun' => $plugin->runs->lastAppliedAt(),
                'nextDue' => $plugin->schedules->nextDueAt(),
            ]);
    }

    /**
     * Resolve a scope and render the preview. Nothing is deleted.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_PRUNE);

        $scope = $this->scopeFromRequest();

        if ($scope->scope !== PruneScope::ELEMENT) {
            $this->requirePro('pruneScope');
        }

        // A preview asks for the honest total, not the next batch — being told "500" when the
        // real number is 40,000 would make every decision from this screen the wrong one.
        $plan = $this->plugin()->pruning->resolve($scope, PHP_INT_MAX);

        $this->plugin()->runs->recordDryRun($plan);

        return $this->asJson([
            'success' => true,
            'count' => $plan->count(),
            'bytes' => $plan->bytes(),
            'protected' => $plan->protectedCount,
            'elementsScanned' => $plan->elementsScanned,
            'html' => Craft::$app->getView()->renderTemplate('visorr/prune/_preview', [
                'plan' => $plan,
                'storage' => $this->plugin()->storage,
            ], \craft\web\View::TEMPLATE_MODE_CP),
        ]);
    }

    /**
     * Apply a prune. Large scopes go to the queue; a single element runs inline so the
     * confirmation is immediate.
     */
    public function actionApply(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_PRUNE);

        $scope = $this->scopeFromRequest();

        if ($scope->scope !== PruneScope::ELEMENT) {
            $this->requirePro('pruneScope');
        }

        $plugin = $this->plugin();
        $plan = $plugin->pruning->resolve($scope);

        if ($plan->count() === 0) {
            return $this->asFailure(Craft::t('visorr', 'Nothing to prune.'));
        }

        // The queue threshold is about *time*, not size. Deleting a revision means deleting its
        // nested elements, one element at a time through Craft's own delete path; a few hundred
        // of those will outlast a web request on any real site.
        if ($plan->count() > 100 || $plan->truncated) {
            QueueHelper::push(new PruneJob([
                'scopeConfig' => $scope->toArray(),
                'trigger' => 'cp',
                'userId' => Craft::$app->getUser()->getId(),
            ]));

            return $this->asSuccess(Craft::t('visorr', 'Pruning {count} revisions in the background.', [
                'count' => $plan->count(),
            ]));
        }

        $result = $plugin->pruning->apply($plan, 'cp');

        if ($result->errors !== []) {
            return $this->asFailure(Craft::t('visorr', 'Pruned {count} revisions with {errors} errors.', [
                'count' => $result->deletedCount,
                'errors' => count($result->errors),
            ]));
        }

        return $this->asSuccess(Craft::t('visorr', '{count} revisions deleted, {size} reclaimed.', [
            'count' => $result->deletedCount,
            'size' => $plugin->storage->formatBytes($result->freedBytes),
        ]));
    }

    private function scopeFromRequest(): PruneScope
    {
        $request = Craft::$app->getRequest();

        $scope = new PruneScope([
            'scope' => (string)$request->getBodyParam('scope', PruneScope::ALL),
            'elementType' => $request->getBodyParam('elementType') ?: null,
            'sectionUid' => $request->getBodyParam('sectionUid') ?: null,
            'canonicalId' => $request->getBodyParam('canonicalId') ? (int)$request->getBodyParam('canonicalId') : null,
            'siteId' => $request->getBodyParam('siteId') ? (int)$request->getBodyParam('siteId') : null,
            'purgeAll' => (bool)$request->getBodyParam('purgeAll', false),
        ]);

        if (!$scope->validate()) {
            throw new \yii\web\BadRequestHttpException(implode('; ', $scope->getErrorSummary(true)));
        }

        return $scope;
    }

    /**
     * @return array<string, string> class => display name
     */
    private function revisionableElementTypes(): array
    {
        return $this->plugin()->revisions->revisionableElementTypes();
    }
}
