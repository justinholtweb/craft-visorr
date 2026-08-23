<?php

namespace justinholtweb\visorr\models;

use craft\base\ElementInterface;
use craft\base\Model;

/**
 * A whole comparison: two versions of an element and what differs between them.
 *
 * "Version" rather than "revision" because either side may be the canonical element as it
 * stands right now. Comparing a revision against what is live is the question people actually
 * arrive with, and Craft cannot answer it at all.
 */
class RevisionDiff extends Model
{
    public ?ElementInterface $left = null;
    public ?ElementInterface $right = null;

    public ?RevisionInfo $leftInfo = null;
    public ?RevisionInfo $rightInfo = null;

    /** @var FieldDiff[] Every field, changed or not; filter with {@see changedFields()}. */
    public array $fields = [];

    /** @var string[] Handles of fields that could not be read on one side or the other. */
    public array $unreadable = [];

    /**
     * @var int Milliseconds spent building the comparison. Shown in the footer of the compare
     * screen, because on a big Matrix this is the number that explains a slow page.
     */
    public int $elapsedMs = 0;

    /**
     * @return FieldDiff[]
     */
    public function changedFields(): array
    {
        return array_values(array_filter($this->fields, fn(FieldDiff $field) => $field->changed));
    }

    public function changedCount(): int
    {
        return count($this->changedFields());
    }

    public function hasChanges(): bool
    {
        foreach ($this->fields as $field) {
            if ($field->changed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Changed fields first, biggest change first, then everything else in layout order.
     *
     * A comparison screen that opens on "Title — unchanged" has buried its own answer.
     *
     * @return FieldDiff[]
     */
    public function sortedFields(): array
    {
        $fields = $this->fields;

        usort($fields, function(FieldDiff $a, FieldDiff $b) {
            if ($a->changed !== $b->changed) {
                return $a->changed ? -1 : 1;
            }

            return $b->magnitude() <=> $a->magnitude();
        });

        return $fields;
    }
}
