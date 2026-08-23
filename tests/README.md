# Testing Visorr

Two suites, split by what they can honestly prove.

## Unit — `tests/unit`

The parts that need nothing but PHP: the word-level diff, and the value formatting that decides
what a field "says". These run against a pinned PHP in this package's own DDEV project, so
`composer install` here cannot disturb the harness the plugin is symlinked into:

```sh
ddev start
ddev composer install
ddev exec vendor/bin/phpunit
```

## Integration — `tests/integration/checks.php`

Everything else. Revisions are not a data structure you can mock usefully — Craft creates them by
duplicating an element, which is exactly the behaviour that makes comparing two of them hard — so
these checks build a real section, save a real entry several times, and read the real revisions
back.

Run them from the plugin-testing harness root:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-visorr/tests/integration/checks.php
```

They are idempotent and self-cleaning: each run works in its own uniquely-named section, sweeps up
anything a previous run left behind, and deletes its fixtures at the end.

A note on timing: the checks `sleep(1)` between saves. Craft skips creating a revision when a save
lands in the same *second* as the previous revision, so without the pause three saves produce one
revision and half the suite would be testing nothing.
