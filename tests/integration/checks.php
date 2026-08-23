<?php
/**
 * Visorr integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-visorr/tests/integration/checks.php
 *
 * Covers what a unit test cannot: real revisions created by real saves, Matrix blocks that are
 * genuinely duplicated per revision, Craft's own prune job being intercepted, and a selective
 * restore writing through Craft's save pipeline.
 *
 * Idempotent and self-cleaning — it builds its own section and deletes it at the end, whatever
 * happens on the way.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\enums\PropagationMethod;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use justinholtweb\visorr\helpers\TextDiff;
use justinholtweb\visorr\helpers\ValueFormatter;
use justinholtweb\visorr\models\PruneScope;
use justinholtweb\visorr\models\RetentionPolicy;
use justinholtweb\visorr\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
Craft::$app->getPlugins()->switchEdition('visorr', Plugin::EDITION_PRO);

$suffix = substr(md5((string)microtime(true)), 0, 6);
$sectionHandle = "visorrCheck$suffix";
$fieldHandle = "visorrBody$suffix";

/**
 * Sweep up after any earlier run that died before its cleanup — a container restart, a fatal in
 * the middle. Without this the harness slowly fills with abandoned `visorrCheck…` sections, and
 * the storage report starts describing the test fixtures rather than the site.
 */
(function() {
    $entries = Craft::$app->getEntries();
    $fields = Craft::$app->getFields();
    $swept = 0;

    foreach ($entries->getAllSections() as $section) {
        if (str_starts_with($section->handle, 'visorrCheck')) {
            $entries->deleteSection($section);
            $swept++;
        }
    }

    foreach ($entries->getAllEntryTypes() as $type) {
        if (str_starts_with($type->handle, 'visorrCheck') || str_starts_with($type->handle, 'visorrBlock')) {
            $entries->deleteEntryType($type);
            $swept++;
        }
    }

    foreach ($fields->getAllFields() as $field) {
        if (str_starts_with($field->handle, 'visorrBody') || str_starts_with($field->handle, 'visorrBlock')) {
            $fields->deleteField($field);
            $swept++;
        }
    }

    if ($swept > 0) {
        echo "Swept $swept leftovers from an earlier run.\n";
    }
})();

// --------------------------------------------------------------------------------------------
// Pure helpers — no database, no Craft state.
// --------------------------------------------------------------------------------------------

section('TextDiff');

check('identical strings produce one equal run', function() {
    $runs = TextDiff::diff('the same words', 'the same words');

    return $runs === [[TextDiff::EQUAL, 'the same words']] ?: json_encode($runs);
});

check('a replaced word is one deletion and one insertion', function() {
    $runs = TextDiff::diff('the quick brown fox', 'the quick red fox');
    $ops = array_column($runs, 0);

    return $ops === [TextDiff::EQUAL, TextDiff::REMOVED, TextDiff::ADDED, TextDiff::EQUAL]
        ?: json_encode($runs);
});

check('reassembling the runs reproduces both sides', function() {
    $old = "First paragraph.\n\nSecond paragraph with more words in it.";
    $new = "First paragraph.\n\nSecond paragraph with rather more words in it, actually.";

    $left = '';
    $right = '';

    foreach (TextDiff::diff($old, $new) as [$op, $text]) {
        if ($op !== TextDiff::ADDED) {
            $left .= $text;
        }
        if ($op !== TextDiff::REMOVED) {
            $right .= $text;
        }
    }

    return ($left === $old && $right === $new) ?: "left=" . var_export($left === $old, true) . " right=" . var_export($right === $new, true);
});

check('an empty side is wholly added or wholly removed', function() {
    return TextDiff::diff('', 'hello') === [[TextDiff::ADDED, 'hello']]
        && TextDiff::diff('hello', '') === [[TextDiff::REMOVED, 'hello']];
});

check('word counts describe what moved', function() {
    $stats = TextDiff::stats('one two three', 'one two three four five');

    return ($stats['added'] === 2 && $stats['removed'] === 0) ?: json_encode($stats);
});

check('html output escapes content that looks like markup', function() {
    $html = TextDiff::toHtml('safe', '<script>alert(1)</script>');

    return (!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;')) ?: $html;
});

check('a huge value still returns runs rather than exhausting memory', function() {
    $old = implode(' ', array_map(fn($i) => "word$i", range(1, 8000)));
    $new = $old . ' and one more';

    $runs = TextDiff::diff($old, $new);

    return $runs !== [] && array_column($runs, 0) !== [TextDiff::EQUAL] ?: 'no difference reported';
});

section('ValueFormatter');

check('markup is flattened to readable text', function() {
    $text = ValueFormatter::htmlToText('<p>First</p><p>Second</p>');

    return $text === "First\n\nSecond" ?: var_export($text, true);
});

check('list items keep their bullets', function() {
    return str_contains(ValueFormatter::htmlToText('<ul><li>One</li><li>Two</li></ul>'), '• One');
});

check('script contents are dropped, not rendered', function() {
    $text = ValueFormatter::htmlToText('<p>Hi</p><script>alert(1)</script>');

    return !str_contains($text, 'alert') ?: $text;
});

check('a lightswitch reads as On or Off', function() {
    return ValueFormatter::text(true) === 'On' && ValueFormatter::text(false) === 'Off';
});

// --------------------------------------------------------------------------------------------
// Fixtures: a real section, a real entry, real revisions.
// --------------------------------------------------------------------------------------------

section('Fixtures');

$fieldsService = Craft::$app->getFields();
$entriesService = Craft::$app->getEntries();
$elementsService = Craft::$app->getElements();

$field = new PlainText([
    'name' => "Visorr Body $suffix",
    'handle' => $fieldHandle,
    'multiline' => true,
]);

check('created a test field', fn() => $fieldsService->saveField($field) ?: implode('; ', $field->getErrorSummary(true)));

// The Matrix machinery is built before the entry type that uses it, and the entry type's layout
// is complete from the start. Adding a field to a layout that already exists works in the
// control panel but not here: Craft memoises field layouts for the life of the request, so
// elements loaded afterwards in the same process still resolve the layout as it was.
$blockFieldHandle = "visorrBlockText$suffix";
$matrixHandle = "visorrBlocks$suffix";

$blockField = new PlainText([
    'name' => "Visorr Block Text $suffix",
    'handle' => $blockFieldHandle,
]);

check('created the block field', fn() => $fieldsService->saveField($blockField) ?: implode('; ', $blockField->getErrorSummary(true)));

$blockType = new EntryType([
    'name' => "Visorr Block $suffix",
    'handle' => "visorrBlock$suffix",
    'hasTitleField' => false,
    'titleFormat' => '{' . $blockFieldHandle . '}',
]);
$blockLayout = new FieldLayout(['type' => Entry::class]);
$blockLayout->setTabs([
    [
        'name' => 'Content',
        'elements' => [
            ['type' => craft\fieldlayoutelements\CustomField::class, 'fieldUid' => $blockField->uid],
        ],
    ],
]);
$blockType->setFieldLayout($blockLayout);

check('created the block entry type', fn() => $entriesService->saveEntryType($blockType) ?: implode('; ', $blockType->getErrorSummary(true)));

$matrixField = new craft\fields\Matrix([
    'name' => "Visorr Blocks $suffix",
    'handle' => $matrixHandle,
]);
$matrixField->setEntryTypes([$blockType]);

check('created the Matrix field', fn() => $fieldsService->saveField($matrixField) ?: implode('; ', $matrixField->getErrorSummary(true)));

$entryType = new EntryType([
    'name' => "Visorr Check $suffix",
    'handle' => $sectionHandle,
]);
$layout = new FieldLayout(['type' => Entry::class]);
// Tabs are configured from arrays rather than constructed directly: a FieldLayoutTab needs its
// layout before its elements can be set, and only the array form wires them in that order.
$layout->setTabs([
    [
        'name' => 'Content',
        'elements' => [
            ['type' => craft\fieldlayoutelements\entries\EntryTitleField::class],
            ['type' => craft\fieldlayoutelements\CustomField::class, 'fieldUid' => $field->uid],
            ['type' => craft\fieldlayoutelements\CustomField::class, 'fieldUid' => $matrixField->uid],
        ],
    ],
]);
$entryType->setFieldLayout($layout);

check('created a test entry type', fn() => $entriesService->saveEntryType($entryType) ?: implode('; ', $entryType->getErrorSummary(true)));

$primarySite = Craft::$app->getSites()->getPrimarySite();

$testSection = new Section([
    'name' => "Visorr Check $suffix",
    'handle' => $sectionHandle,
    'type' => Section::TYPE_CHANNEL,
    'propagationMethod' => PropagationMethod::All,
    'siteSettings' => [
        new Section_SiteSettings([
            'siteId' => $primarySite->id,
            'hasUrls' => false,
        ]),
    ],
]);
$testSection->setEntryTypes([$entryType]);

check('created a test section', fn() => $entriesService->saveSection($testSection) ?: implode('; ', $testSection->getErrorSummary(true)));

$entry = new Entry([
    'sectionId' => $testSection->id,
    'typeId' => $entryType->id,
    'title' => 'Version one',
    'siteId' => $primarySite->id,
]);
$entry->setFieldValue($fieldHandle, 'The quick brown fox jumps over the lazy dog.');

check('saved the entry (revision 1)', fn() => $elementsService->saveElement($entry) ?: implode('; ', $entry->getErrorSummary(true)));

/**
 * Save the entry again with new values, producing another revision.
 */
$resave = function(array $attributes) use (&$entry, $elementsService, $fieldHandle): bool {
    // Craft skips creating a revision when the canonical's `dateUpdated` has the same *second*
    // as the last revision's `dateCreated` — three saves in one second produce one revision.
    // Waiting is the only way to exercise the real save path rather than forcing revisions.
    sleep(1);

    $fresh = Entry::find()->id($entry->id)->status(null)->one();

    foreach ($attributes as $key => $value) {
        if ($key === 'body') {
            $fresh->setFieldValue($fieldHandle, $value);
        } else {
            $fresh->$key = $value;
        }
    }

    $saved = $elementsService->saveElement($fresh);
    $entry = $fresh;

    return $saved;
};

check('saved it again (revision 2)', fn() => $resave([
    'title' => 'Version two',
    'body' => 'The quick red fox jumps over the lazy dog.',
]));

check('saved it a third time (revision 3)', fn() => $resave([
    'title' => 'Version three',
    'body' => 'The quick red fox vaults over the sleeping dog, twice.',
]));

check('saved it a fourth time (revision 4)', fn() => $resave([
    'title' => 'Version four',
    'body' => 'The quick red fox vaults over the sleeping dog, twice, at dawn.',
]));

$canonical = Entry::find()->id($entry->id)->status(null)->one();

check('four revisions exist', function() use ($plugin, $canonical) {
    $count = $plugin->revisions->countFor((int)$canonical->id);

    return $count === 4 ?: "found $count";
});

// --------------------------------------------------------------------------------------------

section('Revision metadata');

$infos = $plugin->revisions->getRevisionInfos($canonical, null, null, true);

check('metadata comes back newest first', function() use ($infos) {
    $nums = array_map(fn($info) => $info->num, $infos);

    return $nums === [4, 3, 2, 1] ?: json_encode($nums);
});

check('the newest revision is flagged as the current state', function() use ($infos) {
    return $infos[0]->isCurrent ?: 'revision ' . $infos[0]->num . ' was not marked current';
});

check('each revision reports a size', function() use ($infos) {
    foreach ($infos as $info) {
        if ($info->sizeBytes <= 0) {
            return "revision {$info->num} reported {$info->sizeBytes} bytes";
        }
    }

    return true;
});

check('the authoring site was recorded', function() use ($infos, $primarySite) {
    foreach ($infos as $info) {
        if ($info->siteId !== (int)$primarySite->id) {
            return "revision {$info->num} recorded site " . var_export($info->siteId, true);
        }
    }

    return true;
});

// --------------------------------------------------------------------------------------------

section('Comparison');

$oldest = end($infos);
$newest = $infos[0];
reset($infos);

$oldElement = $plugin->revisions->getRevisionElement($oldest, (int)$primarySite->id, Entry::class);
$newElement = $plugin->revisions->getRevisionElement($newest, (int)$primarySite->id, Entry::class);

check('both revisions load as elements', fn() => ($oldElement !== null && $newElement !== null) ?: 'one side failed to load');

$diff = $plugin->comparison->compare($oldElement, $newElement);

check('the title is reported as changed', function() use ($diff) {
    foreach ($diff->fields as $field) {
        if ($field->handle === 'title') {
            return $field->changed ?: 'title was not flagged as changed';
        }
    }

    return 'no title diff was produced';
});

check('the diff shows both the old and new title', function() use ($diff) {
    foreach ($diff->fields as $field) {
        if ($field->handle === 'title') {
            return (str_contains((string)$field->diffHtml, '<del') && str_contains((string)$field->diffHtml, '<ins')
                && str_contains((string)$field->diffHtml, 'one') && str_contains((string)$field->diffHtml, 'four'))
                ?: (string)$field->diffHtml;
        }
    }

    return 'no title diff';
});

check('the body field is reported as changed', function() use ($diff, $fieldHandle) {
    foreach ($diff->fields as $field) {
        if ($field->handle === $fieldHandle) {
            return $field->changed ?: 'body was not flagged as changed';
        }
    }

    return 'no body diff was produced';
});

check('the slug is reported as unchanged', function() use ($diff) {
    foreach ($diff->fields as $field) {
        if ($field->handle === 'slug') {
            return !$field->changed ?: 'slug was wrongly flagged as changed';
        }
    }

    return 'no slug diff was produced';
});

check('comparing a revision with itself finds nothing', function() use ($plugin, $newElement) {
    return !$plugin->comparison->compare($newElement, $newElement)->hasChanges()
        ?: 'a revision differed from itself';
});

check('comparing against the live element works', function() use ($plugin, $oldElement, $canonical) {
    $diff = $plugin->comparison->compare($oldElement, $canonical);

    return $diff->hasChanges() ?: 'no changes found between revision 1 and the live entry';
});

check('changed fields sort ahead of unchanged ones', function() use ($diff) {
    $sorted = $diff->sortedFields();
    $seenUnchanged = false;

    foreach ($sorted as $field) {
        if (!$field->changed) {
            $seenUnchanged = true;
        } elseif ($seenUnchanged) {
            return 'a changed field appeared after an unchanged one';
        }
    }

    return true;
});

// --------------------------------------------------------------------------------------------

section('Retention');

$settings = $plugin->getSettings();
$originalPolicies = $settings->policies;

$settings->setPolicies([
    new RetentionPolicy([
        'elementType' => RetentionPolicy::ANY,
        'maxRevisions' => 20,
        'minKeep' => 1,
    ]),
    new RetentionPolicy([
        'elementType' => Entry::class,
        'sectionUid' => $testSection->uid,
        'maxRevisions' => 2,
        'minKeep' => 1,
    ]),
]);
$plugin->retention->reset();

check('the more specific policy wins', function() use ($plugin, $canonical, $testSection) {
    $rule = $plugin->retention->forElement($canonical);

    return $rule->maxRevisions === 2 ?: 'resolved to ' . var_export($rule->maxRevisions, true);
});

check('an element outside the section falls back to the broad policy', function() use ($plugin) {
    $rule = $plugin->retention->resolve(Entry::class, 'some-other-section-uid', null);

    return $rule->maxRevisions === 20 ?: 'resolved to ' . var_export($rule->maxRevisions, true);
});

check('with no policies at all, Craft’s own setting is used', function() use ($plugin, $settings) {
    $settings->setPolicies([]);
    $plugin->retention->reset();

    $rule = $plugin->retention->resolve(Entry::class, null, null);
    $craftMax = Craft::$app->getConfig()->getGeneral()->maxRevisions;

    return $rule->maxRevisions === $craftMax ?: "resolved to {$rule->maxRevisions}, Craft says $craftMax";
});

// Put the section policy back for the pruning checks.
$settings->setPolicies([
    new RetentionPolicy([
        'elementType' => Entry::class,
        'sectionUid' => $testSection->uid,
        'maxRevisions' => 2,
        'minKeep' => 1,
    ]),
]);
$plugin->retention->reset();

// --------------------------------------------------------------------------------------------

section('Pins');

$pinnedRevisionId = $oldest->revisionId;

check('a revision can be pinned', fn() => $plugin->pins->pin($pinnedRevisionId, 'Keep for the checks'));

check('the pin is visible on the metadata', function() use ($plugin, $canonical, $pinnedRevisionId) {
    foreach ($plugin->revisions->getRevisionInfos($canonical) as $info) {
        if ($info->revisionId === $pinnedRevisionId) {
            return ($info->pinned && $info->pinLabel === 'Keep for the checks') ?: 'pin state not reflected';
        }
    }

    return 'pinned revision not found';
});

// --------------------------------------------------------------------------------------------

section('Pruning');

$scope = new PruneScope([
    'scope' => PruneScope::ELEMENT,
    'canonicalId' => (int)$canonical->id,
]);

$plan = $plugin->pruning->resolve($scope, PHP_INT_MAX);

check('the plan proposes the revisions over the limit', function() use ($plan) {
    // Four revisions, a limit of two, the oldest pinned. Counting back from the newest: two
    // survive on the limit, the third would go, and the fourth is pinned so it stays.
    return $plan->count() === 1 ?: "planned {$plan->count()}: " . json_encode($plan->victims);
});

check('the pinned revision is counted as protected, not planned', function() use ($plan, $pinnedRevisionId) {
    foreach ($plan->victims as $victim) {
        if ((int)$victim['revisionId'] === $pinnedRevisionId) {
            return 'the pinned revision was planned for deletion';
        }
    }

    return $plan->protectedCount >= 1 ?: "protectedCount was {$plan->protectedCount}";
});

check('the plan reports the bytes it would reclaim', fn() => $plan->bytes() > 0 ?: 'reported 0 bytes');

check('resolving twice gives the same answer', function() use ($plugin, $scope, $plan) {
    $again = $plugin->pruning->resolve($scope, PHP_INT_MAX);

    return $again->elementIds() === $plan->elementIds()
        ?: json_encode([$plan->elementIds(), $again->elementIds()]);
});

$plannedIds = $plan->elementIds();
$result = $plugin->pruning->apply($plan, 'checks');

check('the execution deleted exactly what the plan named', function() use ($result, $plan) {
    return ($result->deletedCount === $plan->count() && $result->errors === [])
        ?: "deleted {$result->deletedCount} of {$plan->count()}; errors: " . implode('; ', $result->errors);
});

check('the deleted revisions are really gone', function() use ($plannedIds) {
    $remaining = Entry::find()->id($plannedIds)->revisions(true)->status(null)->trashed(null)->count();

    return $remaining === 0 ?: "$remaining survived";
});

check('the pinned revision survived', function() use ($plugin, $pinnedRevisionId) {
    return $plugin->pins->isPinned($pinnedRevisionId)
        && $plugin->revisions->getRevisionInfo($pinnedRevisionId) !== null
        ?: 'the pinned revision was removed';
});

check('the run was written to the ledger', function() use ($plugin, $result) {
    $run = $result->runId !== null ? $plugin->runs->find($result->runId) : null;

    if ($run === null) {
        return 'no ledger row';
    }

    return ((int)$run['deletedCount'] === $result->deletedCount && (int)$run['plannedCount'] === $result->plannedCount)
        ?: json_encode($run);
});

check('the ledger keeps the planned IDs verbatim', function() use ($plugin, $result, $plannedIds) {
    $run = $plugin->runs->find((int)$result->runId);
    $stored = json_decode((string)$run['plannedIds'], true);

    return $stored === $plannedIds ?: json_encode([$plannedIds, $stored]);
});

check('a second prune finds nothing left to do', function() use ($plugin, $scope) {
    return $plugin->pruning->resolve($scope, PHP_INT_MAX)->count() === 0 ?: 'still proposing deletions';
});

// --------------------------------------------------------------------------------------------

section('Craft’s own prune job');

/**
 * The two jobs describe themselves differently — Craft's says "Pruning extra revisions", Visorr's
 * says "Pruning revisions" — so the description of the job that ends up queued is a reliable way
 * to see which one won, without unpacking a serialized payload. Descriptions are stored
 * translation-prepped (`t9n:["category","message"]`), so the category alone is the tell.
 */
$pushPruneJob = function(int $canonicalId) use ($primarySite): ?string {
    // Read the queue table rather than `getJobInfo()`: that returns the *next* job to run,
    // which on a real site is whatever else happens to be waiting.
    $highestBefore = (int)(new craft\db\Query())->from('{{%queue}}')->max('[[id]]');

    Craft::$app->getQueue()->push(new craft\queue\jobs\PruneRevisions([
        'elementType' => Entry::class,
        'canonicalId' => $canonicalId,
        'siteId' => (int)$primarySite->id,
    ]));

    $row = (new craft\db\Query())
        ->select(['id', 'description'])
        ->from('{{%queue}}')
        ->where(['>', 'id', $highestBefore])
        ->orderBy(['id' => SORT_DESC])
        ->one();

    if ($row === false || $row === null) {
        return null;
    }

    craft\helpers\Db::delete('{{%queue}}', ['id' => $row['id']]);

    return (string)$row['description'];
};

check('Craft’s prune job is replaced by Visorr’s when the element has a pin', function() use ($pushPruneJob, $canonical) {
    $description = $pushPruneJob((int)$canonical->id);

    return str_contains((string)$description, '"visorr"') ?: 'queued: ' . var_export($description, true);
});

check('Craft’s prune job is left alone when Visorr has nothing to add', function() use ($pushPruneJob, $plugin, $settings) {
    $held = $settings->policies;
    $settings->setPolicies([]);
    $plugin->retention->reset();

    // An element with no pins and no policy: Visorr would reach the same answer Craft does, so
    // it stays out of the way rather than routing the work through itself for no reason.
    $description = $pushPruneJob(999999);

    $settings->policies = $held;
    $plugin->retention->reset();

    return str_contains((string)$description, 'Pruning extra revisions') ?: 'queued: ' . var_export($description, true);
});

// --------------------------------------------------------------------------------------------

section('Selective restore');

$survivors = $plugin->revisions->getRevisionInfos($canonical, null, null, false);
$restoreSource = null;

foreach ($survivors as $info) {
    if ($info->revisionId === $pinnedRevisionId) {
        $restoreSource = $info;
        break;
    }
}

check('the pinned revision is available to restore from', fn() => $restoreSource !== null ?: 'not found');

if ($restoreSource !== null) {
    $revisionElement = $plugin->revisions->getRevisionElement($restoreSource, (int)$primarySite->id, Entry::class);
    $liveBefore = Entry::find()->id($canonical->id)->status(null)->one();
    $titleBefore = $liveBefore->title;

    // Craft skips a revision when the save lands in the same second as the previous one, so the
    // "a restore is itself undoable" check needs a clear second to be a real test.
    sleep(1);

    $restorePlan = $plugin->restore->plan($revisionElement, $liveBefore, [$fieldHandle]);

    check('the restore plan names only the field asked for', function() use ($restorePlan, $fieldHandle) {
        return $restorePlan->handles === [$fieldHandle] ?: json_encode($restorePlan->handles);
    });

    check('the restore plan reads current → revision', function() use ($restorePlan) {
        $change = $restorePlan->changes[0] ?? null;

        return $change !== null
            && str_contains($change->oldText, 'vaults')
            && str_contains($change->newText, 'quick brown')
            ?: json_encode([$change?->oldText, $change?->newText]);
    });

    $plugin->restore->apply($restorePlan, 'Restored by the Visorr checks');

    $liveAfter = Entry::find()->id($canonical->id)->status(null)->one();

    check('the chosen field was restored', function() use ($liveAfter, $fieldHandle) {
        $value = (string)$liveAfter->getFieldValue($fieldHandle);

        return str_contains($value, 'quick brown fox') ?: $value;
    });

    check('the untouched field was left alone', function() use ($liveAfter, $titleBefore) {
        return $liveAfter->title === $titleBefore
            ?: "title changed from “$titleBefore” to “{$liveAfter->title}”";
    });

    check('the restore created a revision of its own', function() use ($plugin, $canonical) {
        foreach ($plugin->revisions->getRevisionInfos($canonical) as $info) {
            if ($info->notes === 'Restored by the Visorr checks') {
                return true;
            }
        }

        return 'no revision recorded the restore';
    });
}


// --------------------------------------------------------------------------------------------
// Nested elements. This is the part Craft's own data model makes hard: a revision owns *copies*
// of its Matrix blocks with brand-new IDs, so there is no identifier to line two revisions up
// on. Everything below is really one question — does the alignment hold?
// --------------------------------------------------------------------------------------------

section('Matrix blocks');

/**
 * Save the entry with a given list of block texts, in order, as brand-new blocks.
 *
 * Re-keying as `new1`, `new2` … is exactly what Craft's own Matrix input posts for blocks that
 * do not exist yet — the same mechanism the selective restore uses.
 */
$saveBlocks = function(array $texts) use ($entry, $elementsService, $matrixHandle, $blockType, $blockFieldHandle): bool {
    sleep(1);

    $fresh = Entry::find()->id($entry->id)->status(null)->one();
    $value = [];

    foreach ($texts as $i => $text) {
        $value['new' . ($i + 1)] = [
            'type' => $blockType->handle,
            'enabled' => true,
            'fields' => [$blockFieldHandle => $text],
        ];
    }

    $fresh->setFieldValue($matrixHandle, $value);

    return $elementsService->saveElement($fresh);
};

check('saved three blocks', fn() => $saveBlocks(['Alpha', 'Bravo', 'Charlie']));

$withBlocks = $plugin->revisions->getRevisionInfos($canonical, null, 1)[0] ?? null;

check('the blocks made it into a revision', function() use ($plugin, $withBlocks, $primarySite, $matrixHandle) {
    $element = $plugin->revisions->getRevisionElement($withBlocks, (int)$primarySite->id, Entry::class);
    $count = (int)($element?->getFieldValue($matrixHandle)?->count() ?? 0);

    return $count === 3 ?: "revision carried $count blocks";
});

check('an unchanged Matrix field is not reported as changed', function() use ($plugin, $withBlocks, $primarySite, $matrixHandle) {
    // The load-bearing case. Both sides have their own copies of the same three blocks, with
    // different element IDs — comparing serialized values would report all three as rewritten.
    $element = $plugin->revisions->getRevisionElement($withBlocks, (int)$primarySite->id, Entry::class);
    $diff = $plugin->comparison->compare($element, $element);

    foreach ($diff->fields as $fieldDiff) {
        if ($fieldDiff->handle === $matrixHandle) {
            return !$fieldDiff->changed ?: 'an identical Matrix field was flagged as changed';
        }
    }

    return 'no Matrix diff was produced';
});

/**
 * Compare the two newest revisions and return the Matrix field's diff.
 */
$matrixDiff = function() use ($plugin, $canonical, $primarySite, $matrixHandle) {
    $infos = $plugin->revisions->getRevisionInfos($canonical, null, 2);
    $newer = $plugin->revisions->getRevisionElement($infos[0], (int)$primarySite->id, Entry::class);
    $older = $plugin->revisions->getRevisionElement($infos[1], (int)$primarySite->id, Entry::class);

    foreach ($plugin->comparison->compare($older, $newer)->fields as $fieldDiff) {
        if ($fieldDiff->handle === $matrixHandle) {
            return $fieldDiff;
        }
    }

    return null;
};

check('re-saving the same blocks reports no change', function() use ($saveBlocks, $matrixDiff) {
    $saveBlocks(['Alpha', 'Bravo', 'Charlie']);
    $diff = $matrixDiff();

    if ($diff === null) {
        return 'no Matrix diff';
    }

    $statuses = array_map(fn($b) => $b->status, $diff->blocks);

    return $statuses === ['unchanged', 'unchanged', 'unchanged']
        ?: json_encode($statuses);
});

check('editing one block reports exactly one changed block', function() use ($saveBlocks, $matrixDiff) {
    $saveBlocks(['Alpha', 'Bravo edited', 'Charlie']);
    $diff = $matrixDiff();
    $statuses = array_map(fn($b) => $b->status, $diff->blocks);

    return $statuses === ['unchanged', 'changed', 'unchanged'] ?: json_encode($statuses);
});

check('the changed block names the field inside it', function() use ($matrixDiff, $blockFieldHandle) {
    $diff = $matrixDiff();

    foreach ($diff->blocks as $blockDiff) {
        if ($blockDiff->status === 'changed') {
            foreach ($blockDiff->fields as $inner) {
                if ($inner->handle === $blockFieldHandle && $inner->changed) {
                    return str_contains((string)$inner->diffHtml, 'edited') ?: (string)$inner->diffHtml;
                }
            }

            return 'the changed block did not report a changed field';
        }
    }

    return 'no changed block';
});

check('inserting a block at the top does not report the rest as rewritten', function() use ($saveBlocks, $matrixDiff) {
    $saveBlocks(['Zero', 'Alpha', 'Bravo edited', 'Charlie']);
    $diff = $matrixDiff();
    $counts = array_count_values(array_map(fn($b) => $b->status, $diff->blocks));

    return (($counts['added'] ?? 0) === 1 && ($counts['changed'] ?? 0) === 0)
        ?: json_encode(array_map(fn($b) => [$b->status, $b->label], $diff->blocks));
});

check('removing a block reports one removal and nothing else', function() use ($saveBlocks, $matrixDiff) {
    $saveBlocks(['Zero', 'Alpha', 'Charlie']);
    $diff = $matrixDiff();
    $counts = array_count_values(array_map(fn($b) => $b->status, $diff->blocks));

    return (($counts['removed'] ?? 0) === 1 && ($counts['changed'] ?? 0) === 0 && ($counts['added'] ?? 0) === 0)
        ?: json_encode(array_map(fn($b) => [$b->status, $b->label], $diff->blocks));
});

check('reordering blocks reads as moved, not as rewritten', function() use ($saveBlocks, $matrixDiff) {
    $saveBlocks(['Charlie', 'Zero', 'Alpha']);
    $diff = $matrixDiff();
    $counts = array_count_values(array_map(fn($b) => $b->status, $diff->blocks));

    return (($counts['moved'] ?? 0) >= 1 && ($counts['added'] ?? 0) === 0 && ($counts['removed'] ?? 0) === 0)
        ?: json_encode(array_map(fn($b) => [$b->status, $b->oldPosition, $b->newPosition], $diff->blocks));
});

check('the block partial renders the aligned blocks', function() use ($saveBlocks, $matrixDiff) {
    // The alignment is proven above at the model level; this proves the templates that read it
    // agree — the two partials are mutually recursive, so a mistake there is silent rather than
    // fatal, and shows up as an empty comparison screen.
    $saveBlocks(['Zero', 'Alpha edited again', 'Charlie']);
    $diff = $matrixDiff();

    $view = Craft::$app->getView();
    $html = $view->renderTemplate('visorr/_partials/field-diff', [
        'diff' => $diff,
        'restorable' => false,
        'showUnchanged' => false,
    ], craft\web\View::TEMPLATE_MODE_CP);

    return (str_contains($html, 'visorr-block--changed') && str_contains($html, 'edited again'))
        ?: substr($html, 0, 400);
});

check('restoring a Matrix field brings its blocks back', function() use ($plugin, $canonical, $primarySite, $matrixHandle, $blockFieldHandle) {
    // Find the revision that had four blocks starting with "Zero", and put it back.
    $target = null;

    foreach ($plugin->revisions->getRevisionInfos($canonical) as $info) {
        $element = $plugin->revisions->getRevisionElement($info, (int)$primarySite->id, Entry::class);
        $blocks = $element?->getFieldValue($matrixHandle)?->all() ?? [];

        if (count($blocks) === 4) {
            $target = [$info, $element];
            break;
        }
    }

    if ($target === null) {
        return 'no four-block revision to restore from';
    }

    sleep(1);

    $live = Entry::find()->id($canonical->id)->status(null)->one();
    $plan = $plugin->restore->plan($target[1], $live, [$matrixHandle]);
    $plugin->restore->apply($plan, 'Matrix restore check');

    $after = Entry::find()->id($canonical->id)->status(null)->one();
    $texts = array_map(
        fn($b) => (string)$b->getFieldValue($blockFieldHandle),
        $after->getFieldValue($matrixHandle)->all()
    );

    return $texts === ['Zero', 'Alpha', 'Bravo edited', 'Charlie'] ?: json_encode($texts);
});

check('the restored blocks belong to the live entry, not the revision', function() use ($canonical, $matrixHandle) {
    $after = Entry::find()->id($canonical->id)->status(null)->one();

    foreach ($after->getFieldValue($matrixHandle)->all() as $blockElement) {
        if ((int)$blockElement->getPrimaryOwnerId() !== (int)$canonical->id) {
            return 'a restored block is still owned by the revision';
        }
    }

    return true;
});

// --------------------------------------------------------------------------------------------

section('Storage report');

check('the headline total equals the sum of the sections', function() use ($plugin) {
    $overview = $plugin->storage->overview();
    $sum = array_sum(array_column($plugin->storage->bySection(), 'bytes'));

    // Sections cover entries only; anything else lands in the element-type breakdown, so the
    // section sum is a lower bound on the total rather than an exact match.
    return $sum <= $overview['bytes'] ?: "sections=$sum total={$overview['bytes']}";
});

check('the element-type breakdown accounts for the whole total', function() use ($plugin) {
    $overview = $plugin->storage->overview();
    $sum = array_sum(array_column($plugin->storage->byElementType(), 'bytes'));

    $drift = $overview['bytes'] > 0 ? abs($sum - $overview['bytes']) / $overview['bytes'] : 0;

    return $drift < 0.02 ?: "types=$sum total={$overview['bytes']}";
});

check('the report counts the same revisions the metadata does', function() use ($plugin) {
    $overview = $plugin->storage->overview();
    $counted = array_sum(array_column($plugin->storage->byElementType(), 'revisions'));

    return $counted === $overview['revisions'] ?: "types=$counted overview={$overview['revisions']}";
});

// --------------------------------------------------------------------------------------------

section('Editions');

check('Lite has no retention policies', function() use ($plugin, $canonical) {
    Craft::$app->getPlugins()->switchEdition('visorr', Plugin::EDITION_LITE);
    $plugin->retention->reset();

    $rule = $plugin->retention->forElement($canonical);
    $craftMax = Craft::$app->getConfig()->getGeneral()->maxRevisions;

    Craft::$app->getPlugins()->switchEdition('visorr', Plugin::EDITION_PRO);
    $plugin->retention->reset();

    return $rule->maxRevisions === $craftMax ?: "Lite resolved to {$rule->maxRevisions}";
});

check('Lite still protects pins', function() use ($plugin, $pinnedRevisionId) {
    Craft::$app->getPlugins()->switchEdition('visorr', Plugin::EDITION_LITE);
    $protects = $plugin->pins->isPinned($pinnedRevisionId);
    Craft::$app->getPlugins()->switchEdition('visorr', Plugin::EDITION_PRO);

    return $protects ?: 'pins stopped working in Lite';
});

// --------------------------------------------------------------------------------------------

section('Cleanup');

$settings->policies = $originalPolicies;

check('removed the test section', function() use ($entriesService, $testSection) {
    return $entriesService->deleteSection($testSection) ?: 'delete failed';
});

check('removed the test entry type', function() use ($entriesService, $entryType) {
    return $entriesService->deleteEntryType($entryType) ?: 'delete failed';
});

check('removed the test field', function() use ($fieldsService, $field) {
    return $fieldsService->deleteField($field) ?: 'delete failed';
});

check('removed the Matrix field', function() use ($fieldsService, $matrixField) {
    return $fieldsService->deleteField($matrixField) ?: 'delete failed';
});

check('removed the block entry type', function() use ($entriesService, $blockType) {
    return $entriesService->deleteEntryType($blockType) ?: 'delete failed';
});

check('removed the block field', function() use ($fieldsService, $blockField) {
    return $fieldsService->deleteField($blockField) ?: 'delete failed';
});

Craft::$app->getProjectConfig()->saveModifiedConfigData();

echo "\n$passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);
