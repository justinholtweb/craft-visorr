# Release Notes for Visorr

## 5.0.0 — 2026-08-23

Initial release. Version 5.0.0 to match the Craft major it targets, as with the rest of the family.

### Compare

- Field-by-field comparison of any two versions of an element, in either direction, including a
  revision against the current live version.
- Word-level diff with a dependency-free LCS implementation; large values fall back to a
  line-level comparison and then to a side-by-side view rather than exhausting memory.
- Matrix and other nested-element fields are compared block by block, aligned by content so an
  edit reads as changed, a reorder as moved, and only real insertions as added.
- Rich text is flattened to readable text before diffing, so a wrapper class change no longer
  reads as a rewritten paragraph.
- Changed fields sort first, biggest change first.

### Restore

- Selective restore: put back only the fields you choose, leaving the rest of the element alone.
- Every restore is previewed as "what will be overwritten" before it writes.
- Restores save through Craft, so they create a revision of their own and can themselves be undone.
- Craft's whole-element revert is exposed alongside, unchanged.

### Retention and pruning

- Retention policies per element type, section and site, resolved most-specific-first, stored in
  project config.
- Pinned revisions, protected from Visorr's pruning and from Craft's own.
- Craft's `PruneRevisions` job is replaced by Visorr's when — and only when — Visorr has something to
  add.
- Dry-run preview and execution share one resolver; every run is recorded with the IDs it planned
  to delete alongside what it actually deleted.
- Scheduled pruning by cron or from control-panel traffic.
- Console commands: `visorr/prune/plan`, `visorr/prune/apply`, `visorr/report`, `visorr/sites/status`,
  `visorr/sites/backfill`.

### Insight

- Storage report by section, element type and individual element.
- A revision panel on element edit screens showing history depth, retention in force, and pins.

### Multi-site

- The site a revision was authored from is recorded in every edition.
- Revision history in the control panel can be filtered to the current site, for elements shared
  across sites.
- Backfill for revisions written before Visorr was installed.
