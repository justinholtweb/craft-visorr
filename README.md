# Visorr for Craft CMS 5

Craft writes a complete copy of an entry every time you save it, then offers you a list of dates.
Visorr makes that history readable, actionable, and affordable.

- **Compare any two versions**, field by field, with word-level highlighting — including
  revision-against-live, which Craft cannot do at all.
- **Restore just the fields you want** instead of reverting the whole entry.
- **Keep history under control** with retention policies per element type, section and site,
  pinned revisions that pruning will not touch, and a report showing where the weight actually is.

Full documentation: [justinholt.com/plugins/craft-visorr](https://justinholt.com/plugins/craft-visorr/docs)

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

No runtime dependencies. The diff is a couple of hundred lines of PHP in the package.

## Installation

```bash
composer require justinholtweb/craft-visorr
php craft plugin/install visorr
```

## What Craft gives you, and what Visorr adds

|                                        | Craft 5      | Visorr Lite | Visorr Pro |
| -------------------------------------- | ------------ | ---------- | --------- |
| **Price**                              | —            | **Free**   | **$49**, $29/year renewal |
| List an element's revisions            | ✓            | ✓          | ✓         |
| Revert to a revision, wholesale        | ✓            | ✓          | ✓         |
| Compare two revisions, field by field  | —            | ✓          | ✓         |
| Compare a revision against what's live | —            | ✓          | ✓         |
| Matrix blocks compared block by block  | —            | ✓          | ✓         |
| Pin a revision so pruning skips it     | —            | ✓          | ✓         |
| Purge one element's history            | —            | ✓          | ✓         |
| Retention policies                     | one global number | —     | ✓         |
| Prune a section, a type, or everything | —            | —          | ✓         |
| Scheduled pruning                      | —            | —          | ✓         |
| Restore individual fields              | —            | —          | ✓         |
| Storage report                         | —            | —          | ✓         |
| Per-site history for shared elements   | —            | —          | ✓         |
| Console commands                       | —            | `visorr/sites/*` | ✓    |

Lite never takes away anything Craft already gave you. Whole-element revert stays Craft's own, in
both editions.

## Comparing

Open any element and use **Compare** in the Visorr panel, or go to `visorr/compare/<id>`. The screen
opens on the most useful comparison there is — the last saved revision against what is live — and
shows only the fields that moved, biggest change first.

Matrix and other nested-element fields are compared block by block. That is harder than it sounds:
a revision owns *copies* of its blocks with brand-new IDs, so there is nothing to match them on.
Visorr aligns them by content, which means editing a block reads as **changed**, moving one reads as
**moved**, and only a genuine insertion reads as **added** — rather than everything below an edit
reading as rewritten.

## Restoring

Tick the fields you want on the comparison screen and Visorr writes only those onto the live
element. Everything you leave unticked stays exactly as it is.

A restore is an ordinary save, so it creates a revision of its own — which means a restore is
itself undoable.

## Retention

Craft has one global `maxRevisions`. Visorr adds policies, resolved most-specific-first across three
axes:

| Element type | Section | Site | Keep | Expire after | Never fewer than |
| ------------ | ------- | ---- | ---- | ------------ | ---------------- |
| Any          | —       | —    | 20   | 365 days     | 3                |
| Entry        | News    | —    | 5    | 90 days      | 1                |
| Entry        | Home    | —    | 200  | —            | 10               |

Policies live in plugin settings and therefore in project config, so they deploy with the rest of
the site. Set them in `config/visorr.php` if you would rather review them in a pull request — see
`src/config.php` for the full annotated list.

With no policy matching, Craft's own `maxRevisions` applies exactly as it did before Visorr was
installed.

### Craft's own pruning

Craft queues a `PruneRevisions` job on every save, and that job has never heard of a pin or a
per-section policy. Visorr replaces it — but only when it has something to add. An element with no
pins and no matching policy is left entirely to Craft, because there is no value in routing work
through a plugin that would reach the same answer.

## Pinning

Pin a revision and no prune will remove it — not Visorr's, and not Craft's. Retention policy and
editorial value are different things: "keep the last twenty" is a storage decision, "keep the
version we shipped the rebrand with" is not, and a tool that only understands the first will
eventually delete the second.

## Pruning

`visorr/prune` resolves a scope, shows exactly what would go, and applies that list. The preview and
the execution are one resolution, not two implementations that ought to agree. Every run is
recorded with the IDs it planned to delete alongside what it actually deleted, so drift is visible
rather than absorbed.

Pins are re-checked on every batch, so a pin added while a preview sits on screen still wins.

```bash
php craft visorr/prune/plan --scope=section --section=news
php craft visorr/prune/apply --scope=all --force
php craft visorr/prune/apply --due          # for cron
php craft visorr/report                     # where the storage is
```

## The storage report

Revision bloat is never spread evenly. A site with a hundred thousand revisions does not have a
hundred thousand small problems — it has two sections nobody thinks about, one of them full of
Matrix blocks, generating a megabyte per save. The report breaks the weight down by section,
element type and individual element so a first policy writes itself.

Sizes are estimates, and labelled as such: a revision's real cost is spread across five tables and
their indexes. What the numbers are reliable at is the ratio between rows, which is what the report
is for.

## Multi-site history

An element shared across sites has one revision history, so every site's editors see every other
site's work in one undifferentiated list — and reverting to any of it silently replaces content
they have never seen.

Visorr records which site each revision was authored from, **in every edition**, and Pro filters the
control panel accordingly. Recording happens from day one so that a site which upgrades later finds
its history already sorted rather than starting from the day it paid.

Revisions written before Visorr was installed have no recorded site; they surface on the primary
site by default, because showing them nowhere looks exactly like data loss.
`php craft visorr/sites/backfill --site=de --apply` assigns them, when you know where they belong.

## Permissions

| Permission | What it allows |
| --- | --- |
| View revision history and comparisons | The Visorr screens and the sidebar panel |
| Pin and unpin revisions | Protecting revisions from pruning |
| Restore content from a revision | Writing revision content back onto a live element |
| Delete revisions | Pruning and purging |

Visorr's permissions sit on top of Craft's, never instead of them: a user must also be able to view
the element itself, because a revision *is* the element.

## Templating

```twig
{% for revision in craft.visorr.history(entry, 10) %}
  {{ revision.label() }} — {{ revision.dateCreated|datetime }} by {{ revision.creatorName }}
{% endfor %}

{% set diff = craft.visorr.compare(entry, revisionId) %}
{{ diff.changedCount() }} fields changed
```

`craft.visorr` is read-only by design. Nothing there deletes, restores or pins — a template
rendering a page is not the place to change history.

## Testing

29 unit tests over the diff and value formatting; 80 integration checks against a real Craft
install, covering revision creation, comparison, Matrix alignment, pinning, pruning, selective
restore, the interception of Craft's own prune job, and the edition boundary. See
[`tests/README.md`](tests/README.md).
