<?php

namespace justinholtweb\visorr\models;

use craft\base\ElementInterface;
use craft\base\Model;
use craft\elements\User;
use DateTime;

/**
 * One row in a revision list: everything the control panel needs about a revision without
 * loading the revision's content.
 *
 * Most of this comes from Craft's own `revisions` and `elements` tables. Visorr contributes the
 * two facts Craft never recorded — {@see $pinned} and {@see $siteId} — and the estimate in
 * {@see $sizeBytes}, which is the point of the storage report.
 */
class RevisionInfo extends Model
{
    /** @var int The revision element's ID (what you load to read its content). */
    public int $elementId = 0;

    /** @var int The row ID in `revisions` (what a pin and a site record are keyed on). */
    public int $revisionId = 0;

    /** @var int The canonical element this is a revision of. */
    public int $canonicalId = 0;

    /** @var int Craft's revision number, counting up from 1. */
    public int $num = 0;

    public ?string $notes = null;
    public ?DateTime $dateCreated = null;
    public ?int $creatorId = null;
    public ?string $creatorName = null;

    /** @var int|null The site the revision was authored from, once Visorr is recording it. */
    public ?int $siteId = null;

    public bool $pinned = false;
    public ?string $pinLabel = null;

    /**
     * @var int Estimated bytes. "Estimated" is not a hedge: a revision's cost is spread across
     * `elements`, `elements_sites`, `content`, `entries` and one row per nested element, and
     * the only honest number without a table scan per revision is the sum of the content
     * payloads plus a fixed overhead per row. It is right to within a few percent and, more to
     * the point, right *relative to other revisions*, which is what the report is for.
     */
    public int $sizeBytes = 0;

    /** @var int How many nested elements this revision carries. */
    public int $nestedCount = 0;

    /**
     * @var bool Whether this revision is the one matching the element's current state. Craft's
     * own revisions screen hides it; Visorr shows it, labelled, because "compare against what is
     * live" is the comparison people actually want.
     */
    public bool $isCurrent = false;

    /** @var ElementInterface|null Lazily attached when the content is needed. */
    public ?ElementInterface $element = null;

    /** @var User|null */
    public ?User $creator = null;

    /**
     * The label Craft uses in its own revision menu — "Revision 12" — kept identical so the
     * two screens are obviously talking about the same thing.
     */
    public function label(): string
    {
        return "Revision $this->num";
    }
}
