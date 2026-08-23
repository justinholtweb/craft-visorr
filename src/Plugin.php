<?php

namespace justinholtweb\visorr;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\base\Plugin as BasePlugin;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\db\ElementQuery;
use craft\events\CancelableEvent;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\RevisionEvent;
use craft\events\TemplateEvent;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\queue\jobs\PruneRevisions;
use craft\services\Revisions as CraftRevisions;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\visorr\db\Table;
use justinholtweb\visorr\models\Edition;
use justinholtweb\visorr\models\EffectiveRetention;
use justinholtweb\visorr\models\PruneScope;
use justinholtweb\visorr\models\Settings;
use justinholtweb\visorr\queue\jobs\PruneJob;
use justinholtweb\visorr\services\Comparison;
use justinholtweb\visorr\services\Pins;
use justinholtweb\visorr\services\Pruning;
use justinholtweb\visorr\services\Restore;
use justinholtweb\visorr\services\Retention;
use justinholtweb\visorr\services\Revisions;
use justinholtweb\visorr\services\Runs;
use justinholtweb\visorr\services\Schedules;
use justinholtweb\visorr\services\SiteTracking;
use justinholtweb\visorr\services\Storage;
use justinholtweb\visorr\variables\VisorrVariable;
use justinholtweb\visorr\web\assets\cp\CpAsset;
use Throwable;
use yii\base\Event;
use yii\queue\PushEvent;
use yii\queue\Queue as BaseQueue;

/**
 * Visorr — the revision plugin Craft's revision screen has been waiting for.
 *
 * Craft stores a complete copy of an element every time it is saved, then offers a flat list of
 * dates. Visorr makes that history readable (compare any two versions, field by field),
 * actionable (restore just the fields you want), and affordable (retention policy, pruning, and
 * a report showing where the weight actually is).
 *
 * @property-read Revisions $revisions
 * @property-read Comparison $comparison
 * @property-read Restore $restore
 * @property-read Retention $retention
 * @property-read Pruning $pruning
 * @property-read Pins $pins
 * @property-read Runs $runs
 * @property-read Schedules $schedules
 * @property-read SiteTracking $siteTracking
 * @property-read Storage $storage
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = Edition::LITE;
    public const EDITION_PRO = Edition::PRO;

    /** See revision history, comparisons and the report. */
    public const PERMISSION_VIEW = 'visorr:view';

    /** Restore fields from a revision onto the live element. */
    public const PERMISSION_RESTORE = 'visorr:restore';

    /** Delete revisions. Deliberately separate from viewing them. */
    public const PERMISSION_PRUNE = 'visorr:prune';

    /** Pin and unpin revisions. */
    public const PERMISSION_PIN = 'visorr:pin';

    /** Log category used throughout the plugin. */
    public const LOG_CATEGORY = 'visorr';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'revisions' => Revisions::class,
                'comparison' => Comparison::class,
                'restore' => Restore::class,
                'retention' => Retention::class,
                'pruning' => Pruning::class,
                'pins' => Pins::class,
                'runs' => Runs::class,
                'schedules' => Schedules::class,
                'siteTracking' => SiteTracking::class,
                'storage' => Storage::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogging();
        $this->registerPermissions();
        $this->registerCpUrlRules();
        $this->registerTwigVariable();

        if (!Craft::$app->getIsInstalled()) {
            return;
        }

        // Recording which site a revision came from happens in every edition and on every
        // request type — a console resave writes revisions too, and history that skipped them
        // would be worse than no history at all.
        $this->registerSiteRecording();
        $this->registerPruneInterception();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->registerSidebarPanel();
            $this->registerRevisionFiltering();
            $this->registerScheduleTrigger();
        }
    }

    /**
     * Every edition check goes through here rather than calling `is()` directly, so the gate is
     * one method to find and one method to change.
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();

        $subnav = [
            'dashboard' => ['label' => Craft::t('visorr', 'Overview'), 'url' => 'visorr'],
        ];

        if ($this->isPro()) {
            $subnav['report'] = ['label' => Craft::t('visorr', 'Storage'), 'url' => 'visorr/report'];
        }

        if ($user->checkPermission(self::PERMISSION_PRUNE)) {
            $subnav['prune'] = ['label' => Craft::t('visorr', 'Prune'), 'url' => 'visorr/prune'];
        }

        $subnav['pins'] = ['label' => Craft::t('visorr', 'Pinned'), 'url' => 'visorr/pins'];
        $subnav['runs'] = ['label' => Craft::t('visorr', 'History'), 'url' => 'visorr/runs'];

        if ($user->getIsAdmin()) {
            $subnav['settings'] = ['label' => Craft::t('visorr', 'Settings'), 'url' => 'visorr/settings'];
        }

        $item['subnav'] = $subnav;

        return $item;
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('visorr/settings'));
    }

    private function registerLogging(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => self::LOG_CATEGORY,
            'categories' => [self::LOG_CATEGORY],
            'level' => $settings->logLevel,
            'logContext' => false,
            'allowLineBreaks' => true,
            'maxFiles' => 30,
        ]);
    }

    private function registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('visorr', VisorrVariable::class);
            }
        );
    }

    /**
     * Record the authoring site of every revision as it is created.
     */
    private function registerSiteRecording(): void
    {
        Event::on(
            CraftRevisions::class,
            CraftRevisions::EVENT_AFTER_CREATE_REVISION,
            function(RevisionEvent $event) {
                $revisionId = $event->revision?->revisionId;

                if ($revisionId === null) {
                    return;
                }

                $this->siteTracking->record((int)$revisionId, $event->canonical);
            }
        );
    }

    /**
     * Take over Craft's own revision pruning.
     *
     * `Revisions::createRevision()` queues a `PruneRevisions` job on every save. That job knows
     * only `maxRevisions` — it has never heard of a pin or a per-section policy — so leaving it
     * alone would mean both features quietly lose to it on the next save. Two consequences, one
     * of them non-obvious:
     *
     * 1. A pinned revision would be deleted the moment the element was saved often enough.
     * 2. A section configured to keep 200 revisions would still be cut to 50, because Craft's
     *    job runs first and has the final say.
     *
     * The interception is at the queue rather than at the deletion because there is no
     * cancellable hook on the way down. `Element::beforeDelete()` fires its cancellable event
     * *after* running every field's `beforeElementDelete()` — which is where Matrix deletes the
     * element's nested entries — so vetoing there would keep the pinned revision's row and
     * throw away all of its blocks. Refusing the job before it is queued is the only point at
     * which "don't do this" is still true.
     *
     * `yii\queue\Queue` is the class listened on rather than `craft\queue\Queue`, so the
     * interception survives a site swapping in a Redis or SQS queue driver.
     */
    private function registerPruneInterception(): void
    {
        Event::on(
            BaseQueue::class,
            BaseQueue::EVENT_BEFORE_PUSH,
            function(PushEvent $event) {
                $job = $event->job;

                if (!$job instanceof PruneRevisions) {
                    return;
                }

                if (!$this->shouldInterceptPrune($job)) {
                    return;
                }

                // `handled` is what stops yii\queue\Queue::push() from enqueueing the job.
                $event->handled = true;

                Craft::$app->getQueue()->push(new PruneJob([
                    'scopeConfig' => (new PruneScope([
                        'scope' => PruneScope::ELEMENT,
                        'canonicalId' => $job->canonicalId,
                    ]))->toArray(),
                    'trigger' => 'save',
                ]));
            }
        );
    }

    /**
     * Whether Craft's prune job for this element should be replaced by Visorr's.
     *
     * Deliberately conservative: if Visorr has nothing to add — no pins on this element, no
     * policy that differs from Craft's own number — Craft's job is left to run. There is no
     * value in routing work through a plugin that would reach the same answer, and every value
     * in a site behaving exactly as it did before the plugin was installed.
     */
    private function shouldInterceptPrune(PruneRevisions $job): bool
    {
        try {
            /** @var Settings $settings */
            $settings = $this->getSettings();

            if ($settings->protectPins && $this->elementHasPins($job->canonicalId)) {
                return true;
            }

            if (!$this->isPro()) {
                return false;
            }

            $canonical = $this->revisions->canonicalFor($job->canonicalId, $job->siteId);

            if ($canonical === null) {
                return false;
            }

            $rule = $this->retention->forElement($canonical);

            return $rule->source === EffectiveRetention::SOURCE_POLICY;
        } catch (Throwable $e) {
            // Never let a decision about *how* to prune stop a save from completing.
            Craft::warning("Visorr could not evaluate Craft’s prune job: {$e->getMessage()}", self::LOG_CATEGORY);
            return false;
        }
    }

    private function elementHasPins(int $canonicalId): bool
    {
        return (new Query())
            ->from(['p' => Table::PINS])
            ->innerJoin(['r' => CraftTable::REVISIONS], '[[r.id]] = [[p.revisionId]]')
            ->where(['r.canonicalId' => $canonicalId])
            ->exists();
    }

    /**
     * The Visorr panel on element edit screens: how much history this element has, what it
     * weighs, and the way through to comparing it.
     */
    private function registerSidebarPanel(): void
    {
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            function(DefineHtmlEvent $event) {
                /** @var Settings $settings */
                $settings = $this->getSettings();

                if (!$settings->showRevisionPanel) {
                    return;
                }

                /** @var ElementInterface $element */
                $element = $event->sender;

                if (!$this->panelApplies($element)) {
                    return;
                }

                if (!Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW)) {
                    return;
                }

                try {
                    $canonical = $element->getCanonical();
                    $view = Craft::$app->getView();
                    $view->registerAssetBundle(CpAsset::class);

                    $event->html .= $view->renderTemplate('visorr/_partials/sidebar', [
                        'element' => $element,
                        'canonical' => $canonical,
                        'infos' => $this->revisions->getRevisionInfos(
                            $canonical,
                            $this->siteTracking->applies($canonical) ? $element->siteId : null,
                            $settings->panelRevisionLimit,
                        ),
                        'total' => $this->revisions->countFor((int)$canonical->id),
                        'retention' => $this->retention->forElement($canonical),
                    ], View::TEMPLATE_MODE_CP);
                } catch (Throwable $e) {
                    Craft::warning("Visorr sidebar failed to render: {$e->getMessage()}", self::LOG_CATEGORY);
                }
            }
        );
    }

    /**
     * Whether an element edit screen should show the panel at all.
     */
    private function panelApplies(ElementInterface $element): bool
    {
        if ($element->id === null || !$element->hasRevisions()) {
            return false;
        }

        // Nested elements are edited inside their owner, and their history belongs to the
        // owner. A panel on a Matrix block would describe revisions that do not exist.
        return !$element instanceof NestedElementInterface || $element->getOwnerId() === null;
    }

    /**
     * Filter Craft's own revision listings to the site being edited.
     *
     * Two places need it, because Craft asks the question twice: the revision menu on the edit
     * screen builds an element query, and the full revisions screen hands a prepared query to a
     * template. Filtering only the first leaves a "View all revisions" link that contradicts
     * the menu it was opened from.
     */
    private function registerRevisionFiltering(): void
    {
        Event::on(
            ElementQuery::class,
            ElementQuery::EVENT_BEFORE_PREPARE,
            function(CancelableEvent $event) {
                /** @var ElementQuery $query */
                $query = $event->sender;

                if ($query->revisions !== true || !is_int($query->revisionOf)) {
                    return;
                }

                $site = Cp::requestedSite();

                if ($site === null) {
                    return;
                }

                $canonical = $this->revisions->canonicalFor($query->revisionOf, (int)$site->id);

                if (!$this->siteTracking->applies($canonical)) {
                    return;
                }

                $query->andWhere([
                    'elements.id' => $this->siteTracking->allowedElementIds($query->revisionOf, (int)$site->id),
                ]);
            }
        );

        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_TEMPLATE,
            function(TemplateEvent $event) {
                if ($event->template !== '_elements/revisions') {
                    return;
                }

                $element = $event->variables['element'] ?? null;
                $site = Cp::requestedSite();

                if (!$element instanceof ElementInterface || $site === null) {
                    return;
                }

                if (!$this->siteTracking->applies($element)) {
                    return;
                }

                $query = $event->variables['revisionsQuery'] ?? null;

                if ($query instanceof ElementQuery) {
                    // The screen's own query already runs across every site (`site('*')`), so
                    // narrowing by element ID rather than by site is what keeps a revision
                    // visible on the site it was written for and nowhere else.
                    $query->andWhere([
                        'elements.id' => $this->siteTracking->allowedElementIds((int)$element->id, (int)$site->id),
                    ]);
                }
            }
        );
    }

    /**
     * Fires due prunes from control-panel traffic, for sites with no cron.
     *
     * Nothing is deleted inside a page request — the check only ever queues a job, and it runs
     * after the response has been prepared so it cannot slow the page down.
     */
    private function registerScheduleTrigger(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        if (!$this->isPro() || !$settings->scheduleEnabled || $settings->scheduleTrigger !== Settings::TRIGGER_WEB) {
            return;
        }

        $request = Craft::$app->getRequest();

        if ($request->getIsAjax()) {
            return;
        }

        Craft::$app->onAfterRequest(function() {
            try {
                $this->schedules->queueIfDue();
            } catch (Throwable $e) {
                Craft::warning("Visorr could not queue a scheduled prune: {$e->getMessage()}", self::LOG_CATEGORY);
            }
        });
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['visorr'] = 'visorr/dashboard/index';
                $event->rules['visorr/report'] = 'visorr/report/index';
                $event->rules['visorr/prune'] = 'visorr/prune/index';
                $event->rules['visorr/pins'] = 'visorr/pins/index';
                $event->rules['visorr/runs'] = 'visorr/runs/index';
                $event->rules['visorr/runs/<id:\d+>'] = 'visorr/runs/detail';
                $event->rules['visorr/settings'] = 'visorr/settings/index';
                $event->rules['visorr/settings/policies'] = 'visorr/settings/policies';
                $event->rules['visorr/compare/<elementId:\d+>'] = 'visorr/compare/index';
                $event->rules['visorr/history/<elementId:\d+>'] = 'visorr/history/index';
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('visorr', 'Visorr'),
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => Craft::t('visorr', 'View revision history and comparisons'),
                            'nested' => [
                                self::PERMISSION_PIN => [
                                    'label' => Craft::t('visorr', 'Pin and unpin revisions'),
                                ],
                                self::PERMISSION_RESTORE => [
                                    'label' => Craft::t('visorr', 'Restore content from a revision'),
                                ],
                                self::PERMISSION_PRUNE => [
                                    'label' => Craft::t('visorr', 'Delete revisions'),
                                ],
                            ],
                        ],
                    ],
                ];
            }
        );
    }
}
