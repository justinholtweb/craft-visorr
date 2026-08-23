<?php

namespace justinholtweb\visorr\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\base\NestedElementInterface;
use justinholtweb\visorr\helpers\TextDiff;
use justinholtweb\visorr\helpers\ValueFormatter;
use justinholtweb\visorr\models\BlockDiff;
use justinholtweb\visorr\models\FieldDiff;
use justinholtweb\visorr\models\RevisionDiff;
use justinholtweb\visorr\models\Settings;
use justinholtweb\visorr\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Compares two versions of an element, field by field.
 *
 * Both sides are ordinary elements — Craft stores a revision as a full duplicate, not a delta —
 * so this service never needs to reconstruct anything. It loads two elements, walks one field
 * layout, and asks each field what it says on each side.
 *
 * ## Nested elements are the hard part, and the only hard part
 *
 * Everything else is a value comparison. Matrix blocks are not, because a revision owns *copies*
 * of its blocks with fresh IDs, so there is no identifier to line the two sides up on. Comparing
 * them by position alone means inserting one block at the top reports every block below it as
 * rewritten — technically true, useless to read.
 *
 * {@see alignBlocks()} therefore matches blocks in two passes: first the ones whose content
 * signatures are identical, using a longest-common-subsequence so the matches stay in order,
 * then the leftovers between those anchors pair up by type. What survives unpaired is a genuine
 * insertion or deletion. The result is that moving a block reads as "moved", editing one reads
 * as "changed", and only real additions read as added.
 */
class Comparison extends Component
{
    /**
     * How deep to follow nested elements inside nested elements. Past this the comparison
     * reports the field as changed without breaking it down further, which is honest and
     * bounded — a cyclic or pathological structure cannot hang the screen.
     */
    private const MAX_DEPTH = 4;

    /**
     * Native attributes worth comparing, in the order they appear on the screen.
     *
     * `enabled` earns its place: "why did this page disappear" is one of the two questions
     * revision history gets opened for, and Craft's revision list cannot answer it.
     *
     * @var array<string, string>
     */
    private const ATTRIBUTES = [
        'title' => 'Title',
        'slug' => 'Slug',
        'enabled' => 'Enabled',
    ];

    /**
     * Compare two versions of an element.
     *
     * The two sides do not have to be revisions — either may be the canonical element as it
     * stands. They do have to be the same element; comparing unrelated elements is not a
     * feature, it is a bug report waiting to happen.
     */
    public function compare(ElementInterface $left, ElementInterface $right): RevisionDiff
    {
        $started = microtime(true);

        $diff = new RevisionDiff([
            'left' => $left,
            'right' => $right,
        ]);

        $fields = [];

        foreach (self::ATTRIBUTES as $handle => $label) {
            $fields[] = $this->diffAttribute($handle, $label, $left, $right);
        }

        // The right-hand (newer) side supplies the layout: a field added since the older
        // revision was written should appear, and one removed from the layout since should not.
        // Fields only present on the left are added back below, so a deletion is still visible.
        $layout = $right->getFieldLayout() ?? $left->getFieldLayout();
        $seen = [];

        if ($layout !== null) {
            foreach ($layout->getCustomFields() as $field) {
                $seen[$field->handle] = true;
                $fields[] = $this->diffField($field, $left, $right, 0);
            }
        }

        $leftLayout = $left->getFieldLayout();

        if ($leftLayout !== null && $leftLayout !== $layout) {
            foreach ($leftLayout->getCustomFields() as $field) {
                if (!isset($seen[$field->handle])) {
                    $fields[] = $this->diffField($field, $left, $right, 0);
                }
            }
        }

        foreach ($fields as $field) {
            if ($field->restoreBlocker !== null) {
                $diff->unreadable[] = $field->handle;
            }
        }

        $diff->fields = $fields;
        $diff->elapsedMs = (int)round((microtime(true) - $started) * 1000);

        return $diff;
    }

    /**
     * Compare one custom field across two elements.
     *
     * Either element may legitimately be missing the field — a layout changed between the two
     * revisions — in which case the missing side reads as empty, which is what happened as far
     * as the content is concerned.
     */
    public function diffField(
        FieldInterface $field,
        ?ElementInterface $left,
        ?ElementInterface $right,
        int $depth = 0,
    ): FieldDiff {
        $diff = new FieldDiff([
            'handle' => $field->handle,
            'label' => $field->name !== '' ? $field->name : $field->handle,
            'fieldType' => get_class($field),
            'fieldUid' => $field->uid,
            'kind' => FieldDiff::KIND_FIELD,
        ]);

        $leftValue = $this->valueOf($left, $field);
        $rightValue = $this->valueOf($right, $field);

        if ($leftValue === false || $rightValue === false) {
            $diff->changed = false;
            $diff->restorable = false;
            $diff->restoreBlocker = Craft::t('visorr', 'This field could not be read on one side of the comparison.');
            return $diff;
        }

        $leftNested = ValueFormatter::nestedElements($leftValue);
        $rightNested = ValueFormatter::nestedElements($rightValue);

        if ($leftNested !== null || $rightNested !== null) {
            return $this->diffNested($diff, $leftNested ?? [], $rightNested ?? [], $depth);
        }

        $leftSignature = $this->signature($leftValue, $field, $left);
        $rightSignature = $this->signature($rightValue, $field, $right);

        $diff->oldText = ValueFormatter::text($leftValue, $field, $left);
        $diff->newText = ValueFormatter::text($rightValue, $field, $right);
        $diff->changed = $leftSignature !== $rightSignature;

        if ($diff->changed) {
            $this->renderTextDiff($diff);
        }

        return $diff;
    }

    /**
     * Pair up the nested elements of two versions of a field.
     *
     * Returns pairs as `[leftIndex|null, rightIndex|null]` in display order. A pair with both
     * indexes is the same block on both sides — changed or not; one index is an insertion or a
     * deletion.
     *
     * Three passes, each cleaning up after the one before:
     *
     * 1. **Anchor the identical blocks** with a longest-common-subsequence, so unchanged blocks
     *    match in order and an insertion does not shift everything below it.
     * 2. **Pair what is left between anchors by entry type.** A block that was edited keeps its
     *    type far more often than not, and this is what turns "one removed, one added" back into
     *    the "one changed" it actually was.
     * 3. **Reconcile across the whole field.** An LCS cannot anchor a block that moved *past*
     *    another — a rotation reads as a deletion at one end and an insertion at the other — so
     *    leftovers with identical content are re-paired here, wherever they ended up. Identical
     *    content on both sides is a move, by definition.
     *
     * @param NestedElementInterface[] $leftBlocks
     * @param NestedElementInterface[] $rightBlocks
     * @return array<int, array{0: int|null, 1: int|null}>
     */
    public function alignBlocks(array $leftBlocks, array $rightBlocks, int $depth = 0): array
    {
        $leftKeys = array_map(fn(ElementInterface $block) => $this->blockKey($block, $depth), $leftBlocks);
        $rightKeys = array_map(fn(ElementInterface $block) => $this->blockKey($block, $depth), $rightBlocks);

        $anchors = $this->lcsPairs($leftKeys, $rightKeys);

        $pairs = [];
        $leftCursor = 0;
        $rightCursor = 0;

        foreach ([...$anchors, [count($leftBlocks), count($rightBlocks)]] as [$leftAnchor, $rightAnchor]) {
            $pairs = array_merge($pairs, $this->pairGap(
                array_slice($leftKeys, $leftCursor, $leftAnchor - $leftCursor, true),
                array_slice($rightKeys, $rightCursor, $rightAnchor - $rightCursor, true),
                $leftBlocks,
                $rightBlocks,
            ));

            if ($leftAnchor < count($leftBlocks) && $rightAnchor < count($rightBlocks)) {
                $pairs[] = [$leftAnchor, $rightAnchor];
            }

            $leftCursor = $leftAnchor + 1;
            $rightCursor = $rightAnchor + 1;
        }

        $matches = $this->reconcile($pairs, $leftKeys, $rightKeys);

        return $this->displayOrder($matches, count($leftBlocks), count($rightBlocks));
    }

    /**
     * Re-pair leftover deletions and insertions that are really the same block moved.
     *
     * @param array<int, array{0: int|null, 1: int|null}> $pairs
     * @param string[] $leftKeys
     * @param string[] $rightKeys
     * @return array<int, int|null> left index => right index, plus nulls for deletions
     */
    private function reconcile(array $pairs, array $leftKeys, array $rightKeys): array
    {
        $matched = [];
        $looseLeft = [];
        $looseRight = [];

        foreach ($pairs as [$leftIndex, $rightIndex]) {
            if ($leftIndex !== null && $rightIndex !== null) {
                $matched[$leftIndex] = $rightIndex;
            } elseif ($leftIndex !== null) {
                $looseLeft[] = $leftIndex;
            } elseif ($rightIndex !== null) {
                $looseRight[] = $rightIndex;
            }
        }

        // Only exact content matches are re-paired here. Pairing loose blocks by *type* across
        // the whole field would happily marry a block deleted from the top to an unrelated one
        // added at the bottom, and report a rewrite that never happened.
        foreach ($looseLeft as $position => $leftIndex) {
            foreach ($looseRight as $rightPosition => $rightIndex) {
                if ($leftKeys[$leftIndex] === $rightKeys[$rightIndex]) {
                    $matched[$leftIndex] = $rightIndex;
                    unset($looseLeft[$position], $looseRight[$rightPosition]);
                    break;
                }
            }
        }

        foreach ($looseLeft as $leftIndex) {
            $matched[$leftIndex] = null;
        }

        foreach ($looseRight as $rightIndex) {
            $matched['+' . $rightIndex] = $rightIndex;
        }

        return $matched;
    }

    /**
     * Put the pairs into the order the screen reads them: the new version's order, with removed
     * blocks shown where they used to be.
     *
     * @param array<int|string, int|null> $matched
     * @return array<int, array{0: int|null, 1: int|null}>
     */
    private function displayOrder(array $matched, int $leftCount, int $rightCount): array
    {
        $leftOf = [];
        $removals = [];
        $additions = [];

        foreach ($matched as $key => $rightIndex) {
            if (is_string($key)) {
                $additions[$rightIndex] = true;
                continue;
            }

            if ($rightIndex === null) {
                $removals[$key] = true;
                continue;
            }

            $leftOf[$rightIndex] = $key;
        }

        $ordered = [];
        $nextLeft = 0;

        for ($rightIndex = 0; $rightIndex < $rightCount; $rightIndex++) {
            $partner = $leftOf[$rightIndex] ?? null;

            // Emit any blocks that were deleted from ahead of this one, so a removal shows up
            // where the reader last saw it rather than collected at the end.
            while ($partner !== null && $nextLeft < $partner) {
                if (isset($removals[$nextLeft])) {
                    $ordered[] = [$nextLeft, null];
                }
                $nextLeft++;
            }

            if ($partner !== null) {
                $ordered[] = [$partner, $rightIndex];
                $nextLeft = max($nextLeft, $partner + 1);
            } elseif (isset($additions[$rightIndex])) {
                $ordered[] = [null, $rightIndex];
            }
        }

        while ($nextLeft < $leftCount) {
            if (isset($removals[$nextLeft])) {
                $ordered[] = [$nextLeft, null];
            }
            $nextLeft++;
        }

        return $ordered;
    }

    /**
     * Compare a native attribute.
     */
    private function diffAttribute(
        string $handle,
        string $label,
        ElementInterface $left,
        ElementInterface $right,
    ): FieldDiff {
        $diff = new FieldDiff([
            'handle' => $handle,
            'label' => Craft::t('visorr', $label),
            'kind' => FieldDiff::KIND_ATTRIBUTE,
        ]);

        $leftRaw = $left->$handle ?? null;
        $rightRaw = $right->$handle ?? null;

        $diff->oldText = ValueFormatter::text($leftRaw);
        $diff->newText = ValueFormatter::text($rightRaw);
        $diff->changed = $diff->oldText !== $diff->newText;

        if ($diff->changed) {
            $this->renderTextDiff($diff);
        }

        return $diff;
    }

    /**
     * @param NestedElementInterface[] $leftBlocks
     * @param NestedElementInterface[] $rightBlocks
     */
    private function diffNested(FieldDiff $diff, array $leftBlocks, array $rightBlocks, int $depth): FieldDiff
    {
        $diff->kind = FieldDiff::KIND_NESTED;
        $diff->oldText = $this->blocksToText($leftBlocks);
        $diff->newText = $this->blocksToText($rightBlocks);

        if ($depth >= self::MAX_DEPTH) {
            $diff->changed = $diff->oldText !== $diff->newText;
            $diff->restorable = false;
            $diff->restoreBlocker = Craft::t('visorr', 'Nested too deeply to compare block by block.');
            return $diff;
        }

        foreach ($this->alignBlocks($leftBlocks, $rightBlocks, $depth) as [$leftIndex, $rightIndex]) {
            $diff->blocks[] = $this->diffBlock(
                $leftIndex !== null ? ($leftBlocks[$leftIndex] ?? null) : null,
                $rightIndex !== null ? ($rightBlocks[$rightIndex] ?? null) : null,
                $leftIndex,
                $rightIndex,
                $depth,
            );
        }

        foreach ($diff->blocks as $block) {
            if ($block->status !== BlockDiff::UNCHANGED) {
                $diff->changed = true;
                break;
            }
        }

        return $diff;
    }

    private function diffBlock(
        ?ElementInterface $left,
        ?ElementInterface $right,
        ?int $leftIndex,
        ?int $rightIndex,
        int $depth,
    ): BlockDiff {
        $reference = $right ?? $left;

        $block = new BlockDiff([
            'oldPosition' => $leftIndex,
            'newPosition' => $rightIndex,
            'type' => $reference !== null ? ValueFormatter::typeHandle($reference) : '',
            'label' => $reference !== null ? $this->blockLabel($reference) : '',
        ]);

        if ($left === null) {
            $block->status = BlockDiff::ADDED;
        } elseif ($right === null) {
            $block->status = BlockDiff::REMOVED;
        }

        $layout = ($right ?? $left)?->getFieldLayout();

        if ($layout !== null) {
            foreach ($layout->getCustomFields() as $field) {
                $block->fields[] = $this->diffField($field, $left, $right, $depth + 1);
            }
        }

        if ($block->status === BlockDiff::UNCHANGED) {
            foreach ($block->fields as $field) {
                if ($field->changed) {
                    $block->status = BlockDiff::CHANGED;
                    break;
                }
            }
        }

        if (
            $block->status === BlockDiff::UNCHANGED
            && $leftIndex !== null
            && $rightIndex !== null
            && $leftIndex !== $rightIndex
        ) {
            $block->status = BlockDiff::MOVED;
        }

        return $block;
    }

    /**
     * Fill a run of unmatched blocks between two anchors, pairing by entry type.
     *
     * @param array<int, string> $leftKeys index => signature, preserving original indexes
     * @param array<int, string> $rightKeys
     * @param NestedElementInterface[] $leftBlocks
     * @param NestedElementInterface[] $rightBlocks
     * @return array<int, array{0: int|null, 1: int|null}>
     */
    private function pairGap(array $leftKeys, array $rightKeys, array $leftBlocks, array $rightBlocks): array
    {
        $pairs = [];
        $rightIndexes = array_keys($rightKeys);

        foreach (array_keys($leftKeys) as $leftIndex) {
            $type = ValueFormatter::typeHandle($leftBlocks[$leftIndex]);
            $matchedAt = null;

            foreach ($rightIndexes as $position => $rightIndex) {
                if (ValueFormatter::typeHandle($rightBlocks[$rightIndex]) === $type) {
                    $matchedAt = $position;
                    break;
                }
            }

            if ($matchedAt === null) {
                $pairs[] = [$leftIndex, null];
                continue;
            }

            $pairs[] = [$leftIndex, $rightIndexes[$matchedAt]];
            unset($rightIndexes[$matchedAt]);
        }

        foreach ($rightIndexes as $rightIndex) {
            $pairs[] = [null, $rightIndex];
        }

        return $pairs;
    }

    /**
     * Longest common subsequence over two key lists, returned as index pairs.
     *
     * @param string[] $left
     * @param string[] $right
     * @return array<int, array{0: int, 1: int}>
     */
    private function lcsPairs(array $left, array $right): array
    {
        $n = count($left);
        $m = count($right);

        if ($n === 0 || $m === 0) {
            return [];
        }

        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            $rowBelow = $table[$i + 1];
            $row = $table[$i];
            for ($j = $m - 1; $j >= 0; $j--) {
                $row[$j] = $left[$i] === $right[$j]
                    ? $rowBelow[$j + 1] + 1
                    : max($rowBelow[$j], $row[$j + 1]);
            }
            $table[$i] = $row;
        }

        $pairs = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($left[$i] === $right[$j]) {
                $pairs[] = [$i, $j];
                $i++;
                $j++;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $pairs;
    }

    /**
     * A content hash for one nested element, with no IDs in it.
     */
    private function blockKey(ElementInterface $block, int $depth): string
    {
        $signature = ValueFormatter::elementSignature($block, $depth);
        $json = json_encode($signature, JSON_PRESERVE_ZERO_FRACTION);

        // JSON_PRESERVE_ZERO_FRACTION matters more than it looks: without it a whole float is
        // written as an integer, so a value that round-trips through this hash can compare
        // unequal to itself depending on which side of a save it came from.
        return md5($json !== false ? $json : serialize($signature));
    }

    private function blockLabel(ElementInterface $block): string
    {
        $label = trim((string)$block->getUiLabel());

        if ($label !== '') {
            return $label;
        }

        return ValueFormatter::typeHandle($block);
    }

    /**
     * @param NestedElementInterface[] $blocks
     */
    private function blocksToText(array $blocks): string
    {
        $lines = [];

        foreach ($blocks as $index => $block) {
            $lines[] = sprintf('%d. %s (%s)', $index + 1, $this->blockLabel($block), ValueFormatter::typeHandle($block));
        }

        return implode("\n", $lines);
    }

    /**
     * @return mixed `false` when the value could not be read at all.
     */
    private function valueOf(?ElementInterface $element, FieldInterface $field): mixed
    {
        if ($element === null) {
            return null;
        }

        try {
            return $element->getFieldValue($field->handle);
        } catch (Throwable) {
            return false;
        }
    }

    private function signature(mixed $value, FieldInterface $field, ?ElementInterface $element): mixed
    {
        if ($element === null) {
            return null;
        }

        return ValueFormatter::signatureOfValue($value, $field, $element);
    }

    /**
     * Render the inline word diff, unless the values are too big to be worth it.
     */
    private function renderTextDiff(FieldDiff $diff): void
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        $limit = $settings->maxDiffLength;

        if (strlen($diff->oldText) > $limit || strlen($diff->newText) > $limit) {
            // The comparison still reports *that* it changed; it just declines to spend
            // minutes working out exactly which words, for a result nobody could read.
            $diff->diffHtml = null;
            return;
        }

        $diff->diffHtml = TextDiff::toHtml($diff->oldText, $diff->newText);

        $stats = TextDiff::stats($diff->oldText, $diff->newText);
        $diff->wordsAdded = $stats['added'];
        $diff->wordsRemoved = $stats['removed'];
    }
}
