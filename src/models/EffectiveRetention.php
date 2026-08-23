<?php

namespace justinholtweb\visorr\models;

use craft\base\Model;

/**
 * The retention rule that actually applies to one element, after every policy has had its say.
 *
 * Carrying {@see $source} alongside the numbers is deliberate. "Why is this entry only keeping
 * five revisions?" is the support question this feature generates, and an answer that names the
 * rule responsible ends the conversation in one reply.
 */
class EffectiveRetention extends Model
{
    /** No limit is in force at all. */
    public const SOURCE_NONE = 'none';

    /** Craft's own `maxRevisions` general-config setting. */
    public const SOURCE_CRAFT = 'craft';

    /** One of Visorr's retention policies. */
    public const SOURCE_POLICY = 'policy';

    public ?int $maxRevisions = null;
    public ?int $maxAgeDays = null;
    public int $minKeep = 1;

    public string $source = self::SOURCE_NONE;
    public ?string $policyUid = null;
    public string $description = '';

    public function isUnlimited(): bool
    {
        return $this->maxRevisions === null && $this->maxAgeDays === null;
    }
}
