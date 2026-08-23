---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: When there are no revisions, when a comparison looks wrong, when a prune deletes nothing, and when Craft prunes something you pinned.
---

## The entry has no revisions, or fewer than I expected

**Craft skips creating a revision when a save lands in the same second as the last one.** Three
programmatic saves in a loop produce one revision. This is Craft's behaviour, not Visorr's, and it
is the single most common cause of "the history is missing".

Other reasons an element has no history:

- The element type does not support revisions. Only classes whose `hasRevisions()` returns true
  have any — Visorr shows history for all of them, but it cannot invent it.
- Provisional drafts are not revisions. Autosave writes a draft; a revision is written when the
  entry is *saved*.
- `maxRevisions` was lowered at some point and Craft has since caught up on that element.

## The comparison screen is empty, or every field says unchanged

Check `showUnchangedFields`. With it off — the default — a comparison between two revisions that
genuinely do not differ renders as nothing, which is correct but looks broken. Turn it on
temporarily to confirm that is what you are looking at.

If fields you know changed are missing, they may not be in the element's **current** field layout.
Visorr walks one field layout and asks each field for its value; a field removed from the layout
since the revision was written has nowhere to be shown.

## A Matrix field reads as "everything changed"

If a single block edit makes every block below it read as rewritten, alignment did not happen —
which usually means the blocks' *types* differ from what the aligner expected, or the field is a
nested-element field Visorr does not recognise as one.

If a **reorder** reads as a delete plus an insert, that is the third alignment pass not finding
the leftovers identical. Two blocks that look identical to a human but differ in whitespace or in
an invisible field will not hash the same.

Worth knowing when you report either: alignment is content-based because a revision's blocks have
brand-new IDs, so there is nothing structural to match on. There is no ID Visorr is failing to use.

## Rich text shows a change I did not make

Visorr flattens rich text to readable text before diffing precisely to avoid this. If it still
happens, the change is in the text content rather than the markup — a non-breaking space, a
smart-quote substitution, or a trailing space that CKEditor normalised on save.

## A large field will not word-diff

Values over `maxDiffLength` (200,000 characters by default) fall back to a line-level comparison,
and then to side-by-side. The field is still compared and still reported as changed. Raise
`maxDiffLength` if you want, but understand what you are buying: the diff is an O(n²) LCS, and on
a 400KB value it will take minutes to produce something no one can read.

## A prune says it will delete nothing

In order of likelihood:

1. **No policy matches.** With no matching policy Visorr defers to Craft's `maxRevisions` entirely
   and has nothing of its own to delete. Check **Visorr → Storage**, which shows the policy in force
   per section.
2. **`minKeep` is holding the floor.** It beats `maxAgeDays`. An entry with four revisions and a
   `minKeep` of 5 will never lose one, however old they are.
3. **Everything in scope is pinned.** The plan says so explicitly — check the pinned count.
4. **The scope is wrong.** `--section` takes a section *handle*. A typo resolves to no section and
   therefore to no revisions, and that is not currently an error.

## A prune deleted fewer than it planned to

That is the ledger doing its job rather than a bug. Pins are re-checked on every batch, so a pin
added between the plan and the run is honoured, and the run records both numbers. **Visorr →
History** shows planned versus actual for every run.

The same happens if an element was deleted underneath the run.

## Craft pruned a revision I had pinned

Check `protectPins`. With it off, a pin is a bookmark and nothing more, and Craft's own
`PruneRevisions` job will take pinned revisions like any other.

With it on, Visorr replaces that job — **but only for elements it has something to say about**. If
you see this happen with `protectPins` on, that is a bug worth reporting, and the ledger entry for
the run is the useful thing to attach.

## Scheduled pruning is not running

- It is Pro. Confirm the edition first.
- `scheduleEnabled` must be on.
- With `scheduleTrigger` of `cron`, something has to actually call
  `php craft visorr/prune/apply --due --force`. Visorr does not install a cron job.
- Without `--force` the command prompts, and an unattended run will hang at the prompt rather than
  fail. This looks exactly like "nothing happened".
- With `scheduleTrigger` of `web`, the prune fires from control-panel traffic — so on a site
  nobody has logged into, nothing runs. That is the trade-off, not a fault.

## Old revisions all show on the wrong site

Revisions written before Visorr was installed have no recorded authoring site. With
`siteFilterLegacyOnPrimary` on, they surface on the primary site — which is deliberate: showing
them nowhere looks exactly like data loss to whoever is looking.

Assign them properly when you know where they belong:

```bash
php craft visorr/sites/status
php craft visorr/sites/backfill --site=de --apply
```

## Site filtering is on but history is not filtered

`siteFilterMode` of `auto` only filters elements that are **genuinely shared across sites**. An
element that exists on one site is not filtered, because there is nothing to filter. Use `all` if
you want it applied everywhere regardless.

Also confirm the edition: filtering is Pro. The recording happens in both.

## The Visorr panel is not on the edit screen

Either the user lacks the *View revision history and comparisons* permission, or
`showRevisionPanel` is off.

Remember that Visorr's permissions sit on top of Craft's. A user who cannot view the element cannot
view its revisions either, because a revision *is* the element — granting the Visorr permission does
not open a route around section permissions.

## Settings will not save

If a plugin settings screen refuses to save with no visible error, check for a `required` rule
somebody has added. `savePluginSettings()` fails wholesale on one failing rule, so a single empty
required field locks the whole screen. Nothing in Visorr is marked `required` for exactly this
reason.

## Logs

`storage/logs/visorr.log`, at `logLevel`. Every prune is recorded in the ledger regardless of log
level, and **Visorr → History** is usually the faster place to look.
