<?php

namespace justinholtweb\visorr\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\web\Controller;
use justinholtweb\visorr\models\Edition;
use justinholtweb\visorr\models\RevisionInfo;
use justinholtweb\visorr\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Shared plumbing for Visorr's control-panel controllers: permission gates, edition gates, and
 * the two lookups every screen starts from.
 */
abstract class BaseController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        return true;
    }

    protected function plugin(): Plugin
    {
        return Plugin::getInstance();
    }

    /**
     * @throws ForbiddenHttpException if the feature needs Pro and this install is Lite.
     */
    protected function requirePro(string $feature): void
    {
        if (!$this->plugin()->isPro()) {
            throw new ForbiddenHttpException(
                Craft::t('visorr', 'Visorr Pro is required: {feature}', [
                    'feature' => Edition::upsell($feature),
                ])
            );
        }
    }

    /**
     * @throws NotFoundHttpException
     */
    protected function canonical(int $elementId, ?int $siteId = null): ElementInterface
    {
        $element = $this->plugin()->revisions->canonicalFor($elementId, $siteId);

        if ($element === null) {
            throw new NotFoundHttpException("No element with ID $elementId.");
        }

        return $element;
    }

    /**
     * Resolve one side of a comparison. Revision ID 0 means "as it is now".
     *
     * @throws NotFoundHttpException
     */
    protected function side(ElementInterface $canonical, int $revisionId): array
    {
        $plugin = $this->plugin();

        if ($revisionId === 0) {
            return [$canonical, $plugin->revisions->currentInfo($canonical)];
        }

        $info = $plugin->revisions->getRevisionInfo($revisionId);

        if ($info === null || $info->canonicalId !== (int)$canonical->id) {
            throw new NotFoundHttpException("Revision $revisionId does not belong to element {$canonical->id}.");
        }

        $element = $plugin->revisions->getRevisionElement($info, $canonical->siteId, get_class($canonical));

        if ($element === null) {
            throw new NotFoundHttpException("Revision $revisionId could not be loaded.");
        }

        $info->element = $element;

        return [$element, $info];
    }

    /**
     * Whether the current user may see this element's history at all.
     *
     * Two gates, and both matter: Visorr's own permission decides who uses the plugin, and
     * Craft's `canView` decides who may see *this content*. A revision is the content, so a
     * plugin permission alone would be a way around every element-level restriction on the site.
     */
    protected function requireHistoryAccess(ElementInterface $element): void
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null || !$element->canView($user)) {
            throw new ForbiddenHttpException('You are not permitted to view this element.');
        }
    }

    /**
     * @param RevisionInfo[] $infos
     * @return array<int, array<string, mixed>> Shape the revision pickers want.
     */
    protected function pickerOptions(array $infos, ElementInterface $canonical): array
    {
        $formatter = Craft::$app->getFormatter();

        $options = [[
            'value' => 0,
            'label' => Craft::t('visorr', 'Current version'),
        ]];

        foreach ($infos as $info) {
            $options[] = [
                'value' => $info->revisionId,
                'label' => sprintf(
                    '%s — %s%s',
                    $info->label(),
                    $info->dateCreated !== null ? $formatter->asDatetime($info->dateCreated, 'short') : '',
                    $info->creatorName !== null ? ' · ' . $info->creatorName : '',
                ),
            ];
        }

        return $options;
    }
}
