---
title: Usage
slug: usage
order: 30
summary: Comparing, selective restore, pinning, pruning and the ledger, the storage report, multi-site history, Twig and the console.
---

## Comparing

Open any element and click **Compare** in the Visorr panel, or go to `visorr/compare/<id>`.

The screen opens on the most useful comparison there is — **the last saved revision against what
is live** — which is the one comparison Craft cannot do at all. Use the two selectors to pick any
other pair, in either direction.

Only the fields that moved are shown, biggest change first. Turn on `showUnchangedFields` if you
would rather see everything.

### What the diff actually does

Text is compared word by word with a Longest Common Subsequence over word tokens. Rich text is
**flattened to readable text before diffing**, so changing a wrapper class no longer reads as a
rewritten paragraph.

Two fallbacks, in order, so a comparison never fails on size instead of returning something:

1. Values over `maxDiffLength` fall back to a **line-level** comparison.
2. Failing that, a plain **side-by-side** view.

Both still tell you the field changed. What they give up is the word-level highlighting.

### Matrix and other nested elements

This is the hard part, and honestly the only hard part. A revision owns *copies* of its Matrix
blocks with brand-new IDs, so there is nothing to match two revisions' blocks on. Visorr aligns
them by content, in three passes:

1. An LCS over content hashes, which catches everything that did not change.
2. Type-pairing inside each remaining gap, which catches edits.
3. A global reconciliation of identical leftovers — without which a **rotation** reads as a
   deletion plus an insertion, and every block below the change reads as rewritten.

The result is that editing a block reads as **changed**, moving one reads as **moved**, and only a
genuine insertion reads as **added**.

## Restoring

Tick the fields you want on the comparison screen and press **Restore selected**. Visorr writes
only those fields onto the live element; everything you leave unticked stays exactly as it is.

Before it writes, you get a preview of **what will be overwritten** — the current values, not the
incoming ones, because the question you are actually asking is "what am I about to lose".

A restore is an ordinary save. So it creates a revision of its own, which means **a restore is
itself undoable**, and it fires the same events any other save would.

Craft's whole-element revert sits alongside, unchanged, and works in both editions.

Selective restore is Pro.

## Pinning

Pin a revision and no prune will remove it — not Visorr's, and not Craft's.

Retention policy and editorial value are different things. "Keep the last twenty" is a storage
decision. "Keep the version we shipped the rebrand with" is not, and a tool that only understands
the first will eventually delete the second.

Pins are re-checked **on every batch** during a prune, so a pin added while a preview is sitting on
somebody's screen still wins.

Pinning is available in both editions.

## Pruning

**Visorr → Prune** resolves a scope, shows you exactly what would go, and then applies that list.

The preview and the execution are **one resolution**, not two implementations that ought to agree.
`Pruning::resolve()` decides what gets deleted and `apply()` deletes exactly the list it was
handed — a preview that built its own query would be a preview of a different deletion.

Scopes: the whole site, one section, one element type, or one element. Optionally restricted to
revisions authored from one site.

Purging a single element is available in Lite. Everything wider is Pro.

### The ledger

Every run is recorded in `visorr_prunes` with **the IDs it planned to delete alongside what it
actually deleted**. Drift between the two is visible rather than absorbed — if a pin landed
mid-run, or an element was deleted underneath it, the ledger says so.

**Visorr → History** lists past runs.

### Craft's own pruning

Craft queues a `PruneRevisions` job on every save, and that job has never heard of a pin or a
per-section policy.

Visorr replaces it — **but only when it has something to add**. An element with no pins and no
matching policy is left entirely to Craft, because there is no value in routing work through a
plugin that reaches the same answer.

## The storage report

**Visorr → Storage**, or `php craft visorr/report`.

Revision bloat is never spread evenly. A site with a hundred thousand revisions does not have a
hundred thousand small problems — it has two sections nobody thinks about, one of them full of
Matrix blocks, generating a megabyte per save. The report breaks the weight down by section,
element type and individual element, so a first policy more or less writes itself.

**Sizes are estimates and are labelled as such.** A revision's real cost is spread across five
tables and their indexes, and no single query prices that honestly. What the numbers are reliable
at is the **ratio between rows**, which is what the report is for.

The report is Pro.

## Multi-site history

An element shared across sites has one revision history, so every site's editors see every other
site's work in one undifferentiated list — and reverting to any of it silently replaces content
they have never seen.

Visorr records which site each revision was authored from, in **every edition**, and Pro filters the
control panel accordingly.

### Assigning old revisions to a site

Revisions written before Visorr was installed have no recorded site. They surface on the primary
site by default, because showing them nowhere looks exactly like data loss.

```bash
php craft visorr/sites/status                    # what is tracked, what is not
php craft visorr/sites/backfill --site=de        # reports, changes nothing
php craft visorr/sites/backfill --site=de --apply
```

`backfill` reports and stops unless you pass `--apply`. Restrict it to one element with
`--element=<id>`.

## Twig

```twig
{% for revision in craft.visorr.history(entry, 10) %}
  {{ revision.label() }} — {{ revision.dateCreated|datetime }} by {{ revision.creatorName }}
{% endfor %}

{% set diff = craft.visorr.compare(entry, revisionId) %}
{{ diff.changedCount() }} fields changed
```

| Call | Returns |
| --- | --- |
| `history(element, limit, siteId)` | Revision metadata, newest first |
| `count(element)` | How many revisions the element has |
| `compare(element, leftId, rightId)` | A `RevisionDiff`. `rightId` of `0` means "what's live" |
| `retention(element)` | The policy currently in force for this element |
| `isPinned(revisionId)` | Whether that revision is pinned |
| `bytes(element)` | Estimated storage for this element's history |

`craft.visorr` is **read-only by design**. Nothing on it deletes, restores or pins — a template
rendering a page is not the place to change history.

## Console

```bash
php craft visorr/prune/plan  --scope=section --section=news
php craft visorr/prune/apply --scope=all --force
php craft visorr/prune/apply --due          # for cron; exits quietly if nothing is due
php craft visorr/report --limit=20
php craft visorr/sites/status
php craft visorr/sites/backfill --site=de --apply
```

| Option | For |
| --- | --- |
| `--scope` | `all`, `section`, `elementType` or `element` |
| `--section` | Section handle, with `--scope=section` |
| `--elementType` | Element class, with `--scope=elementType` |
| `--element` | Canonical element ID, with `--scope=element` |
| `--site` | Restrict to revisions authored from one site |
| `--force` | Skip the confirmation prompt. Required for unattended runs |
| `--limit` | Override the configured batch size |
| `--due` | Only act if the schedule says a prune is due |

`plan` never writes. `apply` prompts unless you pass `--force`.

A reasonable cron, given `scheduleTrigger` of `cron`:

```
0 3 * * * cd /path/to/site && php craft visorr/prune/apply --due --force
```

`visorr/prune/*` and `visorr/report` are Pro, with one exception: `--scope=element` works in Lite,
matching the one-element purge available in the control panel.

`visorr/sites/status` and `visorr/sites/backfill` work in **both editions**, deliberately. Visorr
records the authoring site in every edition, so the tool for repairing that record should not be
the thing you have to pay for — what Pro adds is *filtering the control panel* by it.
