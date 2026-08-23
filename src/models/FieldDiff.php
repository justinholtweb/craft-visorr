<?php

namespace justinholtweb\visorr\models;

use craft\base\Model;

/**
 * What happened to one field between two versions of an element.
 *
 * A field diff is also the unit of a selective restore: {@see $handle} is what gets copied
 * across, and {@see $restorable} is whether Visorr is willing to copy it.
 */
class FieldDiff extends Model
{
    /** A native element attribute — title, slug, enabled. */
    public const KIND_ATTRIBUTE = 'attribute';

    /** An ordinary custom field. */
    public const KIND_FIELD = 'field';

    /** A field that owns nested elements, compared block by block. */
    public const KIND_NESTED = 'nested';

    public string $handle = '';
    public string $label = '';
    public string $kind = self::KIND_FIELD;

    /** @var string|null Field class, for the icon and for type-specific rendering. */
    public ?string $fieldType = null;

    /** @var string|null Field UID. Null for native attributes, which have none. */
    public ?string $fieldUid = null;

    public bool $changed = false;

    /** @var string Readable rendering of the left (older) value. */
    public string $oldText = '';

    /** @var string Readable rendering of the right (newer) value. */
    public string $newText = '';

    /** @var string|null Pre-rendered inline diff. Null when nothing changed, or for nested fields. */
    public ?string $diffHtml = null;

    public int $wordsAdded = 0;
    public int $wordsRemoved = 0;

    /**
     * @var BlockDiff[] Per-block breakdown, for {@see KIND_NESTED} fields.
     */
    public array $blocks = [];

    /**
     * @var bool Whether this field can be restored on its own.
     *
     * Native attributes and ordinary fields always can. A field is marked unrestorable when
     * reading it back failed, because offering to restore a value Visorr could not read is how
     * a recovery tool destroys the thing it was called in to save.
     */
    public bool $restorable = true;

    /** @var string|null Why it is not restorable, if it is not. */
    public ?string $restoreBlocker = null;

    /**
     * How much moved, as one number, for sorting fields by how interesting they are.
     */
    public function magnitude(): int
    {
        if (!$this->changed) {
            return 0;
        }

        if ($this->kind === self::KIND_NESTED) {
            $total = 0;
            foreach ($this->blocks as $block) {
                $total += $block->magnitude();
            }
            // A nested field with changes but no measurable word movement — blocks reordered,
            // a lightswitch flipped — still outranks an unchanged field.
            return max($total, 1);
        }

        return max($this->wordsAdded + $this->wordsRemoved, 1);
    }

    /**
     * A one-line summary: "3 words added, 1 removed", "2 blocks changed", "set", "cleared".
     */
    public function summary(): string
    {
        if (!$this->changed) {
            return '';
        }

        if ($this->kind === self::KIND_NESTED) {
            $counts = ['added' => 0, 'removed' => 0, 'changed' => 0, 'moved' => 0];
            foreach ($this->blocks as $block) {
                if (isset($counts[$block->status])) {
                    $counts[$block->status]++;
                }
            }

            $parts = [];
            foreach ($counts as $status => $count) {
                if ($count > 0) {
                    $parts[] = "$count $status";
                }
            }

            return $parts === [] ? 'changed' : implode(', ', $parts);
        }

        if ($this->oldText === '') {
            return 'set';
        }

        if ($this->newText === '') {
            return 'cleared';
        }

        $parts = [];
        if ($this->wordsAdded > 0) {
            $parts[] = "+{$this->wordsAdded}";
        }
        if ($this->wordsRemoved > 0) {
            $parts[] = "−{$this->wordsRemoved}";
        }

        return $parts === [] ? 'changed' : implode(' ', $parts);
    }
}
