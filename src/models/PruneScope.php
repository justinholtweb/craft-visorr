<?php

namespace justinholtweb\visorr\models;

use craft\base\Model;

/**
 * What a prune is aimed at.
 *
 * A scope is a value, not a query: it is built from a form or a console flag, handed to
 * {@see \justinholtweb\visorr\services\Pruning::resolve()}, and stored on the ledger row so a
 * past run can be read back and understood.
 */
class PruneScope extends Model
{
    /** One element's revisions. */
    public const ELEMENT = 'element';

    /** Every entry in one section. */
    public const SECTION = 'section';

    /** Every element of one type. */
    public const ELEMENT_TYPE = 'elementType';

    /** Everything with revisions. */
    public const ALL = 'all';

    public string $scope = self::ALL;

    /** @var string|null Element class, for {@see ELEMENT_TYPE}. */
    public ?string $elementType = null;

    /** @var string|null Section UID, for {@see SECTION}. */
    public ?string $sectionUid = null;

    /** @var int|null Canonical element ID, for {@see ELEMENT}. */
    public ?int $canonicalId = null;

    /**
     * @var int|null Restrict to revisions authored from one site. Only meaningful when Visorr
     * has been recording authoring sites; untracked revisions belong to no site and are left
     * alone rather than guessed at.
     */
    public ?int $siteId = null;

    /**
     * @var bool Ignore retention policy and propose *every* revision for deletion, keeping
     * only pinned ones. This is what "purge this entry's history" means, and it is the reason
     * the typed confirmation exists on the control-panel screen.
     */
    public bool $purgeAll = false;

    protected function defineRules(): array
    {
        return [
            [['scope'], 'in', 'range' => [self::ELEMENT, self::SECTION, self::ELEMENT_TYPE, self::ALL]],
            [['canonicalId'], 'required', 'when' => fn(self $model) => $model->scope === self::ELEMENT],
            [['sectionUid'], 'required', 'when' => fn(self $model) => $model->scope === self::SECTION],
            [['elementType'], 'required', 'when' => fn(self $model) => $model->scope === self::ELEMENT_TYPE],
        ];
    }

    public function describe(): string
    {
        return match ($this->scope) {
            self::ELEMENT => "element #$this->canonicalId",
            self::SECTION => "section $this->sectionUid",
            self::ELEMENT_TYPE => (string)$this->elementType,
            default => 'everything',
        };
    }
}
