<?php

namespace justinholtweb\visorr\models;

use craft\base\Model;
use craft\helpers\StringHelper;

/**
 * One retention rule: "for this kind of content, keep this much history".
 *
 * Policies live in plugin settings, and therefore in project config — they are configuration,
 * they should deploy with the rest of the site, and keying them on UIDs rather than IDs means
 * they survive the section being rebuilt in another environment.
 *
 * A policy is *scoped* three ways, from broad to narrow: element type, section (entries only),
 * and site. {@see specificity()} ranks them so the narrowest matching rule wins, which is what
 * lets a site say "keep 10 everywhere, but 200 on the homepage".
 */
class RetentionPolicy extends Model
{
    /** Matches every element type. */
    public const ANY = '*';

    /** @var string Stable identifier, so the settings UI can address a row it has not saved yet. */
    public string $uid = '';

    /** @var string Element class this applies to, or {@see ANY}. */
    public string $elementType = self::ANY;

    /** @var string|null Section UID; entries only, null means every section. */
    public ?string $sectionUid = null;

    /** @var string|null Site UID; null means every site. */
    public ?string $siteUid = null;

    /**
     * @var int|null Keep at most this many revisions per element. Null means no count limit —
     * which is a legitimate thing to want on a policy that only expires by age.
     */
    public ?int $maxRevisions = null;

    /** @var int|null Expire revisions older than this many days. Null means no age limit. */
    public ?int $maxAgeDays = null;

    /**
     * @var int Floor. However old the history is, this many of the most recent revisions
     * always survive — otherwise a quiet section wakes up one morning with no history at all.
     */
    public int $minKeep = 1;

    /** @var bool */
    public bool $enabled = true;

    /** @var string Free-text note, shown in the settings table. */
    public string $note = '';

    public function init(): void
    {
        parent::init();

        if ($this->uid === '') {
            $this->uid = StringHelper::UUID();
        }
    }

    protected function defineRules(): array
    {
        return [
            [['elementType'], 'required'],
            [['maxRevisions', 'maxAgeDays'], 'integer', 'min' => 1],
            [['minKeep'], 'integer', 'min' => 0],
            [['maxRevisions', 'maxAgeDays'], 'default', 'value' => null],
            [['minKeep'], 'default', 'value' => 1],
            [['enabled'], 'boolean'],
            [['note'], 'string', 'max' => 255],
            [['maxRevisions'], 'validateHasALimit', 'skipOnEmpty' => false],
        ];
    }

    /**
     * A policy that limits neither count nor age does nothing at all, and a settings screen
     * full of rules that do nothing is worse than no rules — people trust it.
     *
     * `skipOnEmpty => false` on the rule above is load-bearing: Yii skips inline validators
     * when the attribute is empty, and "empty" is exactly the case being rejected here.
     */
    public function validateHasALimit(): void
    {
        if ($this->maxRevisions === null && $this->maxAgeDays === null) {
            $this->addError('maxRevisions', 'Set a revision limit, an age limit, or both.');
        }
    }

    /**
     * How narrowly this policy is scoped. Higher wins when several match.
     *
     * Element type is worth more than section because a section already implies the element
     * type; the weights only need to order the eight possible combinations, and any weighting
     * where each level outranks the sum of the levels below it would do.
     */
    public function specificity(): int
    {
        return ($this->elementType !== self::ANY ? 4 : 0)
            + ($this->sectionUid !== null ? 2 : 0)
            + ($this->siteUid !== null ? 1 : 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'uid' => $this->uid,
            'elementType' => $this->elementType,
            'sectionUid' => $this->sectionUid,
            'siteUid' => $this->siteUid,
            'maxRevisions' => $this->maxRevisions,
            'maxAgeDays' => $this->maxAgeDays,
            'minKeep' => $this->minKeep,
            'enabled' => $this->enabled,
            'note' => $this->note,
        ];
    }
}
