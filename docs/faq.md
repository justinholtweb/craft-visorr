---
title: FAQ
slug: faq
order: 50
summary: Editions and price, what it stores, whether it works on existing history, performance, multi-site, and what happens if you remove it.
---

## What does Visorr cost?

Lite is free. Pro is **$49**, with a **$29/year** renewal for continued updates. The plugin keeps
working if you stop renewing — you stop receiving updates, not the features you paid for.

## Where is the line between Lite and Pro?

**Seeing versus governing.**

Lite is the comparison screen Craft should have shipped: any two revisions, field by field, word
level, including a revision against what is live. Plus pinning, and purging one element's history.

Pro adds policy across the whole site: retention rules, scheduled pruning, pruning by section or
element type, selective field restore, the storage report, and per-site history filtering.

Lite never takes away anything Craft already gave you. Whole-element revert stays Craft's own, in
both editions.

## Does it work on revisions that already exist?

Yes, and this is the point. Visorr stores no revision data of its own — it reads Craft's
`revisions`, `elements` and `elements_sites` tables. A revision in Craft is a complete duplicated
element, not a delta, so a comparison is "load two elements and walk one field layout".

That means a fresh install has a full comparison screen **immediately**, over history written
years before Visorr existed. There is nothing to backfill and no waiting for new saves.

The one exception is the *authoring site* of a revision, which Craft never recorded. Visorr starts
recording it on install; older revisions can be assigned with `visorr/sites/backfill`.

## Does it work on entries only?

No — on every element type whose class reports `hasRevisions()`. Retention policies are keyed on
element type, with sections as a finer grain for entries.

Drafts are not in scope. A revision is written when something is saved; a draft is a different
thing, and sweeping stale drafts is a different plugin's job.

## What does it add to my database?

Three tables: `visorr_pins`, `visorr_revision_sites` and `visorr_prunes`. All three are small — a row
per pin, a row per revision recording which site it came from, and a row per prune run.

Retention policies are **not** in a table. They live in plugin settings and therefore in project
config, so they deploy with the site and can be reviewed in a pull request.

## Will it slow down saving?

No. Visorr does two things on save: it records the authoring site of the new revision (one insert),
and it decides whether to replace Craft's `PruneRevisions` job with its own.

That second decision is deliberately cheap and deliberately conservative: an element with no pins
and no matching retention policy is **left entirely to Craft**, because there is no value in
routing work through a plugin that would reach the same answer.

## Will the comparison screen fall over on a big entry?

It degrades rather than fails. Values over `maxDiffLength` — 200,000 characters by default — skip
the word-level diff and fall back to a line-level comparison, then to a plain side-by-side view.
The field is still compared and still reported as changed.

The guard exists because a word-level diff is an O(n²) LCS: on a 400KB CKEditor field it would
spend minutes producing a result nobody could read.

## How accurate are the sizes in the storage report?

They are **estimates, and the report labels them as such**. A revision's real cost is spread
across five tables and their indexes, and no single query prices that honestly.

What the numbers are reliable at is the **ratio between rows** — which section is generating ten
times what another is — and that is what the report is for. Do not quote them to a hosting company.

## Can a prune delete something I did not expect?

The design goes out of its way to make that visible. One resolver decides what gets deleted and
`apply()` deletes exactly the list it was handed, so the preview is never a preview of a different
deletion. Every run is recorded with the IDs it *planned* to delete alongside what it *actually*
deleted, so drift shows up in the ledger rather than being absorbed.

Pins are re-checked on every batch, so a pin added while a preview sits on screen still wins.

## Does pinning protect a revision from Craft's own pruning too?

Yes, with `protectPins` on, which is the default. Craft queues a `PruneRevisions` job on every save
and that job has never heard of a pin — so Visorr intercepts it and substitutes its own, for the
elements where that changes the answer.

Turn `protectPins` off and a pin becomes a bookmark and nothing more.

## Can I undo a restore?

Yes. A restore is an ordinary save, so it creates a revision of its own. The state you restored
over is still there, one revision back.

This is also why a restore fires the same events any other save fires — Visorr is not writing
around Craft.

## Does it work on a multi-site install?

Yes, and it fixes a real problem there. An element shared across sites has one revision history,
so every site's editors see every other site's work in one undifferentiated list — and reverting to
any of it silently replaces content they have never seen.

Visorr records the authoring site of every revision in **both editions**, from the day it is
installed, and Pro filters the control panel by it. Recording happens in Lite deliberately: a site
that upgrades later should find its history already sorted, not start from the day it paid.

## Does it phone home?

No. There are no outbound HTTP requests in the plugin.

## What happens if I uninstall it?

Craft's revision history is untouched, because Visorr never owned it. Uninstalling drops Visorr's
three tables — you lose your pins, the record of which site each revision came from, and the prune
ledger. The revisions themselves are Craft's and stay exactly as they are.

Anything a prune already deleted is gone, the same as if Craft had pruned it.

## Which versions are supported?

Craft CMS 5.3+ and PHP 8.2+. No runtime dependencies beyond Craft's own, and no build step — the
diff is a few hundred lines of PHP in the package and the control-panel JavaScript is a classic
script.
