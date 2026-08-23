<?php

namespace justinholtweb\visorr\models;

/**
 * The one pure description of what each edition can do.
 *
 * Nothing else in the plugin decides where the line falls. Controllers and templates ask
 * {@see Plugin::isPro()} for the gate and this class for the copy, so the boundary can be
 * read — and changed — in one place.
 *
 * The line is **seeing versus governing**. Lite gives you the comparison screen Craft should
 * have shipped, plus pinning and one-entry-at-a-time purging. Pro adds policy across the whole
 * site. Lite deliberately takes nothing away that Craft already gave you for free: whole-entry
 * revert stays Craft's own, untouched, in both editions.
 */
abstract class Edition
{
    public const LITE = 'lite';
    public const PRO = 'pro';

    /**
     * Features available in Lite (and therefore in Pro as well).
     *
     * @return array<string, string> handle => human description
     */
    public static function liteFeatures(): array
    {
        return [
            'compare' => 'Compare any two revisions field by field, including against the current version',
            'revisionPanel' => 'A revision panel on the element edit screen, with author, size and notes',
            'pins' => 'Pin revisions so no prune — Visorr’s or Craft’s — can remove them',
            'purgeElement' => 'Purge one element’s revisions from the control panel, after a dry run',
        ];
    }

    /**
     * Features that require Pro.
     *
     * @return array<string, string> handle => human description
     */
    public static function proFeatures(): array
    {
        return [
            'retention' => 'Retention policies per element type, section and site, overriding maxRevisions',
            'schedule' => 'Scheduled pruning, by cron or from control panel traffic',
            'pruneScope' => 'Prune across a section, an element type or the whole site',
            'selectiveRestore' => 'Restore individual fields from a revision instead of the whole element',
            'storageReport' => 'The storage report: how many revisions exist and what they weigh',
            'siteFiltering' => 'Per-site revision history for elements shared across sites',
            'console' => 'Console commands, for keeping history bounded from CI',
        ];
    }

    /**
     * Whether a feature handle is available in the given edition.
     */
    public static function allows(string $edition, string $feature): bool
    {
        if (isset(self::liteFeatures()[$feature])) {
            return true;
        }

        return $edition === self::PRO && isset(self::proFeatures()[$feature]);
    }

    /**
     * The sentence shown on an upgrade prompt for a Pro-only feature.
     */
    public static function upsell(string $feature): string
    {
        return self::proFeatures()[$feature] ?? 'This feature requires Visorr Pro.';
    }
}
