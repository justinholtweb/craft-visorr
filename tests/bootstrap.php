<?php

/**
 * Bootstrap for the unit suite.
 *
 * Only the helpers that can answer a question without a database live here — the diff
 * algorithm and the value formatting. Everything that needs a real Craft, real elements and
 * real revisions is exercised by `tests/integration/checks.php` against the plugin-testing
 * harness, because a mocked revision is not a revision.
 */

require __DIR__ . '/../vendor/autoload.php';
