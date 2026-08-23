<?php

namespace justinholtweb\visorr\models;

use craft\base\Model;
use Psr\Log\LogLevel;

/**
 * Plugin settings.
 *
 * Retention policies live here rather than in a table on purpose: they are configuration, they
 * belong in project config so they deploy with the site, and keying them on UIDs means they
 * survive being rebuilt in another environment.
 *
 * Nothing in here is `required`. A fresh install has to be able to save any one setting without
 * every other one being filled in first — a `required` rule fails `savePluginSettings()`
 * wholesale and locks the settings screen.
 */
class Settings extends Model
{
    /** Pruning is fired by a real cron job calling `visorr/prune/apply --due`. */
    public const TRIGGER_CRON = 'cron';

    /** Pruning is fired from control-panel traffic, for sites with no cron. */
    public const TRIGGER_WEB = 'web';

    /** Per-site history for every element that is shared across sites. */
    public const SITE_FILTER_AUTO = 'auto';

    /** Per-site history only for the sections listed in {@see $siteFilterSectionUids}. */
    public const SITE_FILTER_SELECTED = 'selected';

    /** Per-site history everywhere, including elements that only exist on one site. */
    public const SITE_FILTER_ALL = 'all';

    /**
     * @var array<int, array<string, mixed>> Retention policies, as raw arrays.
     * Read them through {@see getPolicies()}, which hands back models.
     */
    public array $policies = [];

    /**
     * @var bool Whether a pinned revision is protected from *every* prune, including Craft's
     * own `PruneRevisions` job. Off, a pin is only a bookmark.
     */
    public bool $protectPins = true;

    /**
     * @var bool Whether the revision panel appears in the sidebar of element edit screens.
     */
    public bool $showRevisionPanel = true;

    /**
     * @var int How many revisions the sidebar panel lists before linking out to the full screen.
     */
    public int $panelRevisionLimit = 10;

    /**
     * @var bool Whether a comparison shows fields whose value did not change.
     */
    public bool $showUnchangedFields = false;

    /**
     * @var int Values longer than this are compared but not word-diffed — a 400KB CKEditor
     * field would otherwise spend minutes in an O(n²) LCS for a result nobody can read.
     * The comparison still reports *that* it changed.
     */
    public int $maxDiffLength = 200000;

    /**
     * @var bool Whether scheduled pruning runs at all (Pro).
     */
    public bool $scheduleEnabled = false;

    /** @var string One of {@see TRIGGER_CRON}, {@see TRIGGER_WEB}. */
    public string $scheduleTrigger = self::TRIGGER_CRON;

    /** @var int Hours between scheduled prunes. */
    public int $scheduleIntervalHours = 24;

    /**
     * @var int How many revisions one prune run deletes before stopping. A prune deletes real
     * elements one at a time; a site with 200,000 stale revisions should chip away at them
     * across runs rather than hold a worker for an hour.
     */
    public int $pruneBatchSize = 500;

    /**
     * @var bool Whether pruned revisions are hard-deleted. Revisions are already history; a
     * soft-deleted revision keeps every row it was taking up, which defeats the point.
     */
    public bool $hardDelete = true;

    /**
     * @var bool Whether revision history is filtered to the site it was authored from (Pro).
     */
    public bool $siteFilterEnabled = false;

    /** @var string One of the SITE_FILTER_* constants. */
    public string $siteFilterMode = self::SITE_FILTER_AUTO;

    /** @var string[] Section UIDs, when the mode is {@see SITE_FILTER_SELECTED}. */
    public array $siteFilterSectionUids = [];

    /**
     * @var bool Whether revisions with no recorded site — everything from before Visorr was
     * installed — show on the primary site. Off, they show nowhere, which is a good way to
     * make a client think their history was deleted.
     */
    public bool $siteFilterLegacyOnPrimary = true;

    /** @var string */
    public string $logLevel = LogLevel::INFO;

    protected function defineRules(): array
    {
        return [
            [['panelRevisionLimit'], 'integer', 'min' => 1, 'max' => 100],
            [['maxDiffLength'], 'integer', 'min' => 1000],
            [['scheduleIntervalHours'], 'integer', 'min' => 1, 'max' => 8760],
            [['pruneBatchSize'], 'integer', 'min' => 1, 'max' => 100000],
            [['scheduleTrigger'], 'in', 'range' => [self::TRIGGER_CRON, self::TRIGGER_WEB]],
            [
                ['siteFilterMode'],
                'in',
                'range' => [self::SITE_FILTER_AUTO, self::SITE_FILTER_SELECTED, self::SITE_FILTER_ALL],
            ],
            [
                [
                    'protectPins', 'showRevisionPanel', 'showUnchangedFields', 'scheduleEnabled',
                    'hardDelete', 'siteFilterEnabled', 'siteFilterLegacyOnPrimary',
                ],
                'boolean',
            ],
            [['policies'], 'validatePolicies', 'skipOnEmpty' => false],
        ];
    }

    /**
     * `skipOnEmpty => false` because "no policies" is a state worth letting through explicitly
     * rather than one Yii silently skips — and because an inline validator that never runs on
     * an empty array is a trap this family has already been caught by once.
     */
    public function validatePolicies(): void
    {
        foreach ($this->getPolicies() as $i => $policy) {
            if (!$policy->validate()) {
                foreach ($policy->getFirstErrors() as $error) {
                    $this->addError('policies', "Policy " . ($i + 1) . ": $error");
                }
            }
        }
    }

    /**
     * @return RetentionPolicy[]
     */
    public function getPolicies(): array
    {
        return array_map(
            fn(array $config) => new RetentionPolicy($config),
            array_values(array_filter($this->policies, 'is_array'))
        );
    }

    /**
     * @param RetentionPolicy[]|array<int, array<string, mixed>> $policies
     */
    public function setPolicies(array $policies): void
    {
        $this->policies = array_values(array_map(
            fn($policy) => $policy instanceof RetentionPolicy ? $policy->toArray() : $policy,
            $policies
        ));
    }
}
