---
title: Installation
slug: installation
order: 10
summary: Requirements, install, editions, permissions, and reading your first comparison.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

No runtime dependencies and no build step. The word-level diff is a few hundred lines of PHP in
the package rather than a Composer dependency.

## Install

```bash
composer require justinholtweb/craft-visorr
php craft plugin/install visorr
```

The install migration creates three tables — `visorr_pins`, `visorr_revision_sites` and
`visorr_prunes`. Everything else Visorr reads comes from Craft's own `revisions`, `elements` and
`elements_sites`, which is why Visorr can compare revisions written years before it was installed.

## Editions

|                                        | Craft 5           | Lite  | Pro   |
| -------------------------------------- | ----------------- | ----- | ----- |
| **Price**                              | —                 | Free  | $49, $29/year renewal |
| List an element's revisions            | ✓                 | ✓     | ✓     |
| Revert to a revision, wholesale        | ✓                 | ✓     | ✓     |
| Compare two revisions, field by field  | —                 | ✓     | ✓     |
| Compare a revision against what's live | —                 | ✓     | ✓     |
| Matrix blocks compared block by block  | —                 | ✓     | ✓     |
| Pin a revision so pruning skips it     | —                 | ✓     | ✓     |
| Purge one element's history            | —                 | ✓     | ✓     |
| Retention policies                     | one global number | —     | ✓     |
| Prune a section, a type, or everything | —                 | —     | ✓     |
| Scheduled pruning                      | —                 | —     | ✓     |
| Restore individual fields              | —                 | —     | ✓     |
| Storage report                         | —                 | —     | ✓     |
| Per-site history for shared elements   | —                 | —     | ✓     |
| Console commands                       | —                 | `visorr/sites/*` | ✓ |

The line is **seeing versus governing**. Lite is the comparison screen Craft should have shipped,
plus pinning and purging one element at a time. Pro adds policy across the whole site.

Lite never takes away anything Craft already gave you — whole-element revert stays Craft's own, in
both editions.

## Permissions

Four permissions, under **Settings → Users → the group → Visorr**:

| Permission | What it allows |
| --- | --- |
| View revision history and comparisons | The Visorr screens and the sidebar panel |
| Pin and unpin revisions | Protecting revisions from pruning |
| Restore content from a revision | Writing revision content back onto a live element |
| Delete revisions | Pruning and purging |

They sit **on top of** Craft's, never instead of them. A user must also be able to view the element
itself, because a revision *is* the element — granting "view revision history" does not open a
door around your existing section permissions.

## Your first comparison

1. Open any entry that has been saved more than once.
2. In the sidebar, find the **Visorr** panel and click **Compare**.

The screen opens on the most useful comparison there is — the last saved revision against what is
live — and lists only the fields that moved, biggest change first. That last part matters more than
it sounds: an entry with forty fields and one edited paragraph should not be forty rows of grey.

If the panel is not there, either the user lacks *View revision history*, or `showRevisionPanel` is
off. See [Configuration](configuration).

## Nothing to switch on

There is no step three. Visorr reads history Craft was already writing, so a fresh install has a
full comparison screen immediately, with no backfill and no waiting for new saves.

The one thing worth doing on day one is running the storage report — `php craft visorr/report`, or
**Visorr → Storage** — before you write any retention policy. Revision bloat is never spread evenly,
and the report is what tells you which two sections are actually the problem.
