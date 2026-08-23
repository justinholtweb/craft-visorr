<?php

namespace justinholtweb\visorr\controllers;

use Craft;
use craft\errors\InvalidElementException;
use justinholtweb\visorr\Plugin;
use justinholtweb\visorr\web\assets\compare\CompareAsset;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * The comparison screen, and the selective restore that hangs off it.
 *
 * They live in one controller because they are one workflow: nobody restores a field they have
 * not just looked at, and the checkboxes that choose what to restore are columns on the diff
 * that showed them why.
 */
class CompareController extends BaseController
{
    /**
     * Compare two versions of an element.
     */
    public function actionIndex(int $elementId): Response
    {
        $request = Craft::$app->getRequest();
        $siteId = $request->getParam('siteId');
        $canonical = $this->canonical($elementId, $siteId !== null ? (int)$siteId : null);

        $this->requireHistoryAccess($canonical);

        $plugin = $this->plugin();
        $infos = $plugin->revisions->getRevisionInfos(
            $canonical,
            $plugin->siteTracking->applies($canonical) ? $canonical->siteId : null,
            null,
            true,
        );

        if ($infos === []) {
            return $this->asCpScreen()
                ->title(Craft::t('visorr', 'Compare “{title}”', ['title' => $canonical->getUiLabel()]))
                ->contentTemplate('visorr/compare/empty', ['element' => $canonical]);
        }

        // Default to the most useful comparison there is: the last saved revision against what
        // is live. That is the question people arrive with, and Craft cannot answer it at all.
        $rightId = (int)($request->getParam('right') ?? 0);
        $leftId = (int)($request->getParam('left') ?? $this->defaultLeft($infos, $rightId));

        [$left, $leftInfo] = $this->side($canonical, $leftId);
        [$right, $rightInfo] = $this->side($canonical, $rightId);

        $diff = $plugin->comparison->compare($left, $right);
        $diff->leftInfo = $leftInfo;
        $diff->rightInfo = $rightInfo;

        Craft::$app->getView()->registerAssetBundle(CompareAsset::class);

        return $this->asCpScreen()
            ->title(Craft::t('visorr', 'Compare “{title}”', ['title' => $canonical->getUiLabel()]))
            ->crumbs([
                ['label' => Craft::t('visorr', 'Visorr'), 'url' => 'visorr'],
                ['label' => (string)$canonical->getUiLabel(), 'url' => (string)$canonical->getCpEditUrl()],
                ['label' => Craft::t('visorr', 'Compare'), 'current' => true],
            ])
            ->contentTemplate('visorr/compare/index', [
                'element' => $canonical,
                'diff' => $diff,
                'infos' => $infos,
                'options' => $this->pickerOptions($infos, $canonical),
                'leftId' => $leftId,
                'rightId' => $rightId,
                'settings' => $this->plugin()->getSettings(),
                'canRestore' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_RESTORE)
                    && $canonical->canSave(Craft::$app->getUser()->getIdentity()),
            ]);
    }

    /**
     * Show what a selective restore would do, without doing it.
     */
    public function actionPreviewRestore(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePro('selectiveRestore');
        $this->requirePermission(Plugin::PERMISSION_RESTORE);

        [$plan, $canonical] = $this->buildPlan();

        return $this->asJson([
            'count' => $plan->count(),
            'hasChanges' => $plan->hasChanges(),
            'blocked' => $plan->blocked,
            'html' => Craft::$app->getView()->renderTemplate('visorr/compare/_restore-preview', [
                'plan' => $plan,
                'element' => $canonical,
            ], \craft\web\View::TEMPLATE_MODE_CP),
        ]);
    }

    /**
     * Apply a selective restore.
     */
    public function actionRestore(): Response
    {
        $this->requirePostRequest();
        $this->requirePro('selectiveRestore');
        $this->requirePermission(Plugin::PERMISSION_RESTORE);

        [$plan, $canonical] = $this->buildPlan();

        if ($plan->handles === []) {
            return $this->asFailure(Craft::t('visorr', 'Nothing was selected to restore.'));
        }

        $note = Craft::$app->getRequest()->getBodyParam('note') ?: null;

        try {
            $this->plugin()->restore->apply($plan, $note);
        } catch (InvalidElementException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(
            Craft::t('visorr', '{count} fields restored.', ['count' => $plan->count()]),
            [],
            (string)$canonical->getCpEditUrl(),
        );
    }

    /**
     * Whole-element revert — Craft's own, exposed here so the comparison screen can offer it
     * next to the selective one rather than sending people back to another screen to find it.
     */
    public function actionRevert(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_RESTORE);

        $revisionId = (int)Craft::$app->getRequest()->getRequiredBodyParam('revisionId');
        $elementId = (int)Craft::$app->getRequest()->getRequiredBodyParam('elementId');

        $canonical = $this->canonical($elementId);
        $this->requireHistoryAccess($canonical);

        [$revision] = $this->side($canonical, $revisionId);

        $restored = $this->plugin()->restore->revertAll($revision, (int)Craft::$app->getUser()->getId());

        return $this->asSuccess(
            Craft::t('visorr', 'Reverted to revision {num}.', ['num' => $revisionId]),
            [],
            (string)$restored->getCpEditUrl(),
        );
    }

    /**
     * @return array{0: \justinholtweb\visorr\models\RestorePlan, 1: \craft\base\ElementInterface}
     */
    private function buildPlan(): array
    {
        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $revisionId = (int)$request->getRequiredBodyParam('revisionId');
        $handles = $request->getBodyParam('handles') ?: [];

        if (!is_array($handles)) {
            throw new BadRequestHttpException('handles must be an array of field handles.');
        }

        $siteId = $request->getBodyParam('siteId');
        $canonical = $this->canonical($elementId, $siteId !== null ? (int)$siteId : null);

        $this->requireHistoryAccess($canonical);

        if (!$canonical->canSave(Craft::$app->getUser()->getIdentity())) {
            throw new \yii\web\ForbiddenHttpException('You are not permitted to edit this element.');
        }

        [$revision, $info] = $this->side($canonical, $revisionId);

        $plan = $this->plugin()->restore->plan($revision, $canonical, array_map('strval', $handles));
        $plan->revisionInfo = $info;

        return [$plan, $canonical];
    }

    /**
     * The revision to show on the left by default.
     *
     * Craft writes a revision on every save, so the newest revision *is* the current state —
     * comparing the two would open the screen on "nothing changed", which is the one answer
     * nobody navigated here for. The default is therefore the newest revision that is not
     * already the live version, which reads as "what did the last save change?".
     *
     * @param \justinholtweb\visorr\models\RevisionInfo[] $infos
     */
    private function defaultLeft(array $infos, int $rightId): int
    {
        foreach ($infos as $info) {
            if ($info->revisionId !== $rightId && !($rightId === 0 && $info->isCurrent)) {
                return $info->revisionId;
            }
        }

        foreach ($infos as $info) {
            if ($info->revisionId !== $rightId) {
                return $info->revisionId;
            }
        }

        return $infos[0]->revisionId ?? 0;
    }
}
