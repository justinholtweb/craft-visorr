<?php

/**
 * Visorr configuration.
 *
 * Copy this file to `config/visorr.php`. Anything set here overrides what is in the control
 * panel and cannot be changed from it, which is the point: a production site's retention policy
 * should be reviewable in a pull request.
 *
 * Every value can be environment-keyed the same way Craft's own config files are:
 *
 *     return [
 *         '*' => [ ... ],
 *         'dev' => [ 'scheduleEnabled' => false ],
 *     ];
 */

return [
    // Retention policies. The most specific match wins: element type beats section beats site.
    // `elementType` accepts '*' for any. `sectionUid` and `siteUid` are UIDs, not handles, so
    // they survive the section being rebuilt in another environment.
    'policies' => [
        // [
        //     'elementType' => craft\elements\Entry::class,
        //     'sectionUid' => null,
        //     'siteUid' => null,
        //     'maxRevisions' => 25,
        //     'maxAgeDays' => 365,
        //     'minKeep' => 5,
        //     'enabled' => true,
        //     'note' => 'House default',
        // ],
    ],

    // A pinned revision is skipped by every prune, Visorr's and Craft's own.
    'protectPins' => true,

    // The panel on element edit screens.
    'showRevisionPanel' => true,
    'panelRevisionLimit' => 10,

    // Comparison.
    'showUnchangedFields' => false,
    'maxDiffLength' => 200000,

    // Pruning.
    'hardDelete' => true,
    'pruneBatchSize' => 500,
    'scheduleEnabled' => false,
    'scheduleTrigger' => 'cron',
    'scheduleIntervalHours' => 24,

    // Per-site revision history for elements shared across sites.
    'siteFilterEnabled' => false,
    'siteFilterMode' => 'auto',
    'siteFilterSectionUids' => [],
    'siteFilterLegacyOnPrimary' => true,

    'logLevel' => 'info',
];
