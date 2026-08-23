<?php

namespace justinholtweb\visorr\controllers;

use Craft;
use justinholtweb\visorr\models\RetentionPolicy;
use justinholtweb\visorr\models\Settings;
use justinholtweb\visorr\Plugin;
use yii\web\Response;

/**
 * Plugin settings, including the retention policies.
 *
 * Policies are saved through plugin settings rather than a table of their own, so they land in
 * project config and deploy with the site. That is also why the form posts whole policy rows
 * rather than editing them one at a time: project config wants a document, not a stream of
 * mutations.
 */
class SettingsController extends BaseController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin();

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = $this->plugin();

        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        return $this->asCpScreen()
            ->title(Craft::t('visorr', 'Visorr settings'))
            ->selectedSubnavItem('settings')
            ->addCrumb(Craft::t('visorr', 'Visorr'), 'visorr')
            ->action('visorr/settings/save')
            ->submitButtonLabel(Craft::t('visorr', 'Save settings'))
            ->contentTemplate('visorr/settings/index', [
                'settings' => $settings,
                'policies' => $settings->getPolicies(),
                'sections' => Craft::$app->getEntries()->getAllSections(),
                'sites' => Craft::$app->getSites()->getAllSites(),
                'elementTypes' => $this->revisionableElementTypes(),
                'craftMax' => Craft::$app->getConfig()->getGeneral()->maxRevisions,
                'isPro' => $plugin->isPro(),
            ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $plugin = $this->plugin();
        $request = Craft::$app->getRequest();

        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        $settings->setAttributes($request->getBodyParam('settings', []), false);
        $settings->setPolicies($this->policiesFromRequest());

        if (!$settings->validate()) {
            Craft::$app->getSession()->setError(Craft::t('visorr', 'Couldn’t save settings.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings,
                'policies' => $settings->getPolicies(),
            ]);

            return $this->renderTemplate('visorr/settings/index', [
                'settings' => $settings,
                'policies' => $settings->getPolicies(),
                'sections' => Craft::$app->getEntries()->getAllSections(),
                'sites' => Craft::$app->getSites()->getAllSites(),
                'elementTypes' => $this->revisionableElementTypes(),
                'craftMax' => Craft::$app->getConfig()->getGeneral()->maxRevisions,
                'isPro' => $plugin->isPro(),
            ]);
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            return $this->asFailure(Craft::t('visorr', 'Couldn’t save settings.'));
        }

        $plugin->retention->reset();

        return $this->asSuccess(Craft::t('visorr', 'Settings saved.'), [], 'visorr/settings');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function policiesFromRequest(): array
    {
        $rows = Craft::$app->getRequest()->getBodyParam('policies', []);

        if (!is_array($rows)) {
            return [];
        }

        $policies = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // An all-blank row is what an empty "add another" line posts. Dropping it silently
            // is right; failing validation on a row nobody filled in is not.
            if (($row['maxRevisions'] ?? '') === '' && ($row['maxAgeDays'] ?? '') === '') {
                continue;
            }

            $elementType = (string)($row['elementType'] ?? RetentionPolicy::ANY);
            $sectionUid = !empty($row['sectionUid']) ? (string)$row['sectionUid'] : null;
            $siteUid = !empty($row['siteUid']) ? (string)$row['siteUid'] : null;

            $policies[] = (new RetentionPolicy([
                // Derived from the scope rather than generated, so re-saving settings that did
                // not change produces an identical project-config document. A random UUID here
                // would make every save look like an edit to everyone else's deployment.
                'uid' => $this->policyUid($elementType, $sectionUid, $siteUid),
                'elementType' => $elementType,
                'sectionUid' => $sectionUid,
                'siteUid' => $siteUid,
                'maxRevisions' => ($row['maxRevisions'] ?? '') !== '' ? (int)$row['maxRevisions'] : null,
                'maxAgeDays' => ($row['maxAgeDays'] ?? '') !== '' ? (int)$row['maxAgeDays'] : null,
                'minKeep' => ($row['minKeep'] ?? '') !== '' ? (int)$row['minKeep'] : 1,
                'enabled' => (bool)($row['enabled'] ?? true),
                'note' => (string)($row['note'] ?? ''),
            ]))->toArray();
        }

        return $policies;
    }

    /**
     * A stable identifier for a policy: its scope, hashed. Two policies with the same scope
     * would be the same rule twice, so a collision here is a duplicate the author meant to
     * notice.
     */
    private function policyUid(string $elementType, ?string $sectionUid, ?string $siteUid): string
    {
        return substr(md5(implode('|', [$elementType, $sectionUid ?? '', $siteUid ?? ''])), 0, 32);
    }

    /**
     * @return array<string, string>
     */
    private function revisionableElementTypes(): array
    {
        return [RetentionPolicy::ANY => Craft::t('visorr', 'Any element type')]
            + $this->plugin()->revisions->revisionableElementTypes();
    }
}
