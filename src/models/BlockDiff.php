<?php

namespace justinholtweb\visorr\models;

use craft\base\Model;

/**
 * One nested element (Matrix block, and anything else owned by a field) inside a field diff.
 *
 * Blocks are aligned between the two versions before they are compared — see
 * {@see \justinholtweb\visorr\services\Comparison::alignBlocks()} — because a revision's nested
 * elements are duplicates with new IDs, so there is no identifier to match them on. That is why
 * a block carries a {@see $status} rather than simply an old and a new value.
 */
class BlockDiff extends Model
{
    public const ADDED = 'added';
    public const REMOVED = 'removed';
    public const CHANGED = 'changed';
    public const UNCHANGED = 'unchanged';
    public const MOVED = 'moved';

    public string $status = self::UNCHANGED;

    /** @var string Entry type handle, or the element's ref handle. */
    public string $type = '';

    /** @var string What the author sees this block as: its title, or its type. */
    public string $label = '';

    /** @var int|null Position in the older version, 0-based. Null when the block was added. */
    public ?int $oldPosition = null;

    /** @var int|null Position in the newer version, 0-based. Null when the block was removed. */
    public ?int $newPosition = null;

    /** @var FieldDiff[] The block's own fields. */
    public array $fields = [];

    public function magnitude(): int
    {
        $total = 0;

        foreach ($this->fields as $field) {
            $total += $field->magnitude();
        }

        return $this->status === self::UNCHANGED ? $total : max($total, 1);
    }
}
