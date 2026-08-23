---
title: Configuration
slug: configuration
order: 20
summary: Retention policies, the settings that govern pruning and the panel, and why policy belongs in project config.
---

Visorr's settings live at **Settings → Plugins → Visorr**, or in `config/visorr.php`. Anything set in
the file overrides the control panel and cannot be changed from it — which is the point: a
production site's retention policy should be reviewable in a pull request, not editable by whoever
is logged in.

Copy `src/config.php` from the package to `config/visorr.php` for a fully annotated starting point.
Every value can be environment-keyed the way Craft's own config files are:

```php
return [
    '*'   => [ /* ... */ ],
    'dev' => ['scheduleEnabled' => false],
];
```

## Retention policies

The one setting that matters. Craft has a single global `maxRevisions`; Visorr resolves policy
across three axes and takes **the most specific match**: element type beats section beats site.

```php
'policies' => [
    [
        'elementType'  => craft\elements\Entry::class,  // '*' for any
        'sectionUid'   => null,
        'siteUid'      => null,
        'maxRevisions' => 20,
        'maxAgeDays'   => 365,
        'minKeep'      => 3,
        'enabled'      => true,
        'note'         => 'House default',
    ],
],
```

| Key | What it does |
| --- | --- |
| `elementType` | A class name, or `'*'` for any revisionable element |
| `sectionUid` | Narrows to one section. A **UID**, not a handle |
| `siteUid` | Narrows to revisions authored from one site |
| `maxRevisions` | Keep at most this many |
| `maxAgeDays` | Delete anything older than this |
| `minKeep` | The floor. Never go below this many, whatever `maxAgeDays` says |
| `enabled` | Turn a policy off without deleting it |
| `note` | For whoever reads this in six months |

**Sections and sites are keyed by UID rather than handle** so that a policy survives the section
being rebuilt in another environment. The settings screen shows you names and writes UIDs.

`minKeep` is the setting that saves you. `maxAgeDays` alone will happily empty the history of an
entry nobody has touched in two years — which is exactly the entry whose history you will want.

With **no policy matching**, Craft's own `maxRevisions` applies exactly as it did before Visorr was
installed. Visorr adds governance; it does not take the default away.

## Pins

```php
'protectPins' => true,
```

A pinned revision is skipped by every prune — Visorr's *and* Craft's own `PruneRevisions` job. Turn
this off and a pin becomes a bookmark and nothing more, which is almost certainly not what you
want. It is a setting rather than a hard-coded rule only because "protect from Craft's job too"
means intercepting a core job, and that is the sort of thing that should be switchable.

## The revision panel

```php
'showRevisionPanel'  => true,
'panelRevisionLimit' => 10,
```

The panel in the sidebar of element edit screens: history depth, the retention in force, pins, and
a link to the comparison screen. `panelRevisionLimit` is how many it lists before linking out.

## Comparison

```php
'showUnchangedFields' => false,
'maxDiffLength'       => 200000,
```

`showUnchangedFields` off is the default because a forty-field entry with one edited paragraph
should not render as forty rows.

`maxDiffLength` is a guard, not a limit on what can be compared. Values longer than it are still
compared and still reported as changed — they just skip the word-level diff, because a 400KB
CKEditor field would otherwise spend minutes in an O(n²) LCS producing a result no human can read.

## Pruning

```php
'hardDelete'            => true,
'pruneBatchSize'        => 500,
'scheduleEnabled'       => false,
'scheduleTrigger'       => 'cron',   // or 'web'
'scheduleIntervalHours' => 24,
```

`hardDelete` defaults to on because a revision is already history: soft-deleting one keeps every
row it was occupying, which defeats the entire purpose of pruning it.

`pruneBatchSize` exists because a prune deletes real elements one at a time. A site with 200,000
stale revisions should chip away across runs rather than hold a worker for an hour.

`scheduleTrigger` is `cron` — a real cron job calling `visorr/prune/apply --due` — or `web`, which
fires from control-panel traffic for sites that have no cron. Prefer `cron`. `web` means the prune
runs when somebody happens to be logged in, which is not a schedule so much as a coincidence.

Scheduled pruning is Pro.

## Per-site history

```php
'siteFilterEnabled'         => false,
'siteFilterMode'            => 'auto',   // 'auto' | 'selected' | 'all'
'siteFilterSectionUids'     => [],
'siteFilterLegacyOnPrimary' => true,
```

An element shared across sites has one revision history, so every site's editors see every other
site's work in one list. With filtering on, the control panel shows each site its own.

- `auto` — filter every element that is genuinely shared across sites
- `selected` — only the sections in `siteFilterSectionUids`
- `all` — everywhere, including elements that only exist on one site

`siteFilterLegacyOnPrimary` decides where revisions written *before* Visorr was installed appear.
They have no recorded site, so with this off they appear nowhere — which looks exactly like data
loss to a client. On, they surface on the primary site until you
[backfill](usage#assigning-old-revisions-to-a-site) them.

Site filtering is Pro. The **recording** is not: Visorr writes the authoring site for every
revision in both editions, from the day it is installed, so a site that upgrades later finds its
history already sorted rather than starting from the day it paid.

## Logging

```php
'logLevel' => 'info',
```

Writes to `storage/logs/visorr.log`. Every prune is also recorded in the ledger regardless of log
level — see [Usage](usage#the-ledger).

## A note on `required`

Nothing in Visorr's settings is marked `required`, deliberately. A `required` rule fails
`savePluginSettings()` wholesale, so on a fresh install one empty field locks the entire settings
screen until every other field is filled in.
