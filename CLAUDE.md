# Visorr — Craft CMS 5 Plugin

## Project Overview

Visorr makes Craft's revision history readable, actionable and affordable: compare any two versions
field by field, restore just the fields you want, and govern how much history each kind of content
keeps. Distributed as `justinholtweb/craft-visorr`. Lite (free) + Pro ($49).

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- **No runtime dependencies and no build step.** The diff is a hand-written LCS in
  `helpers/TextDiff.php`; the control-panel JS is a classic script.

## Architecture

- Namespace `justinholtweb\visorr`, package `justinholtweb/craft-visorr`, handle `visorr`.

### The load-bearing idea: a revision is a whole element, not a delta

`Revisions::createRevision()` calls `Elements::duplicateElement()`. So a comparison is "load two
elements, walk one field layout" — no new storage, no new format, and it works on history written
before Visorr was installed. A selective restore is a field copy. And that same completeness is what
makes revisions expensive, which is why retention has to be governable per section rather than by
one global number.

### The invariant

`Pruning::resolve()` is the only thing that decides what gets deleted. `apply()` deletes exactly
the list it is handed. A preview that builds its own query is a preview of a different deletion.
The ledger stores the planned IDs *and* the outcome so drift is visible. Pins are re-checked per
batch.

### Nested elements are the hard part, and the only hard part

A revision owns *copies* of its Matrix blocks with new IDs, so there is nothing to match two
revisions' blocks on. `Comparison::alignBlocks()` aligns in three passes — LCS over content hashes,
type-pairing within each gap, then a global reconciliation of identical leftovers (without which a
rotation reads as a deletion plus an insertion). `ValueFormatter` keeps *signature* (structural,
for equality) and *text* (readable, for display) deliberately separate.

### Data

Three tables — `visorr_pins`, `visorr_revision_sites`, `visorr_prunes`. Everything else is read from
Craft's own `revisions`/`elements`/`elements_sites`. Retention policies live in plugin settings,
and therefore in project config, so they deploy with the site.

### Services

`revisions` (read side, metadata and sizes) · `comparison` · `restore` · `retention` (the authority
on "too many") · `pruning` · `pins` · `runs` (ledger) · `schedules` · `siteTracking` · `storage`.

## Testing

No local PHP on this Mac.

```sh
# integration — 80 checks, idempotent and self-sweeping
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-visorr/tests/integration/checks.php

# unit — 29 tests, in this package's own DDEV project
cd ~/Sites/craft-visorr
ddev exec vendor/bin/phpunit
ddev exec vendor/bin/ecs check
```

Running both DDEV projects at once has proved flaky here — the harness's database container gets
stopped. Start the one you need.

The integration checks `sleep(1)` between saves on purpose: Craft skips creating a revision when a
save lands in the same second as the last one, so without it three saves produce one revision.

## Coding conventions

- `Craft::t('visorr', '…')` for user-facing strings; `src/translations/en/visorr.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Every edition check goes through `Plugin::isPro()`; the boundary itself is described once, in
  `models/Edition.php`

See `[[craft-visorr-gotchas]]` and `[[craft-plugin-gotchas]]` in the shared memory for the traps
this build turned up.
