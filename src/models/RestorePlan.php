<?php

namespace justinholtweb\visorr\models;

use craft\base\ElementInterface;
use craft\base\Model;

/**
 * What a selective restore would put back.
 *
 * The plan runs in the same direction the author is thinking in: the *current* value on the
 * left, the value that would replace it on the right. That is the opposite of how the
 * comparison screen is usually read, and getting it backwards on a confirmation screen is how
 * someone restores the thing they were trying to get rid of.
 */
class RestorePlan extends Model
{
    public ?ElementInterface $canonical = null;
    public ?ElementInterface $revision = null;
    public ?RevisionInfo $revisionInfo = null;

    /** @var string[] Field and attribute handles that would be written. */
    public array $handles = [];

    /** @var FieldDiff[] Current → revision, for the handles being restored. */
    public array $changes = [];

    /** @var string[] Handles that were asked for but cannot be restored, with the reason. */
    public array $blocked = [];

    /**
     * Whether restoring these fields would actually change anything.
     */
    public function hasChanges(): bool
    {
        foreach ($this->changes as $change) {
            if ($change->changed) {
                return true;
            }
        }

        return false;
    }

    public function count(): int
    {
        return count($this->handles);
    }
}
