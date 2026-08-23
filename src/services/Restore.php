<?php

namespace justinholtweb\visorr\services;

use Craft;
use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\errors\InvalidElementException;
use justinholtweb\visorr\helpers\ValueFormatter;
use justinholtweb\visorr\models\FieldDiff;
use justinholtweb\visorr\models\RestorePlan;
use justinholtweb\visorr\Plugin;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Puts content back — either the whole element, or just the fields you pick.
 *
 * Whole-element revert is Craft's, called through {@see revertAll()} and not reimplemented:
 * `Revisions::revertToRevision()` already does it correctly, including the new revision that
 * records the revert, and a plugin that shadowed it would only be a second thing to keep in
 * step with Craft.
 *
 * **Selective restore is the part Craft cannot do**, and the reason this service exists. It
 * comes directly from a real recovery: a propagation bug on a multi-site build wiped the
 * per-site Matrix blocks off a shared homepage, and the fix was a one-off console command that
 * put two fields back from history and left everything else alone. Reverting the whole entry
 * would have thrown away a fortnight of unrelated edits.
 *
 * ## How a field is copied
 *
 * Ordinary fields round-trip through `serializeValue()` and `setFieldValue()` — the same path
 * Craft uses to load a posted form, so anything Craft can save from a form, Visorr can restore.
 *
 * Nested-element fields need one extra step. `Matrix::serializeValue()` keys its blocks by
 * *their* element IDs, and those blocks belong to the revision. Handing that straight to the
 * canonical would ask Craft to move another element's blocks. Re-keying them as `new1`, `new2`
 * … is exactly what a form posts for blocks that do not exist yet, so Craft creates fresh
 * blocks on the canonical with the revision's content and drops the ones that were there.
 */
class Restore extends Component
{
    /** Native attributes a selective restore is allowed to write. */
    private const ATTRIBUTES = ['title', 'slug', 'enabled'];

    /**
     * Work out what restoring these handles would change, without changing anything.
     *
     * @param string[] $handles
     */
    public function plan(ElementInterface $revision, ElementInterface $canonical, array $handles): RestorePlan
    {
        $plan = new RestorePlan([
            'canonical' => $canonical,
            'revision' => $revision,
            'handles' => [],
        ]);

        $comparison = Plugin::getInstance()->comparison;
        $layout = $canonical->getFieldLayout();
        $fields = [];

        if ($layout !== null) {
            foreach ($layout->getCustomFields() as $field) {
                $fields[$field->handle] = $field;
            }
        }

        foreach ($handles as $handle) {
            if (in_array($handle, self::ATTRIBUTES, true)) {
                $plan->handles[] = $handle;
                $plan->changes[] = $this->attributeChange($handle, $canonical, $revision);
                continue;
            }

            $field = $fields[$handle] ?? null;

            if ($field === null) {
                // The field is not on the canonical's layout any more. Restoring it would write
                // a value nothing will ever read, and Craft would drop it on the next save.
                $plan->blocked[$handle] = Craft::t('visorr', 'No longer in this element’s field layout.');
                continue;
            }

            // Left is *current*, right is *what would replace it* — the direction a
            // confirmation screen has to read in.
            $change = $comparison->diffField($field, $canonical, $revision);

            if (!$change->restorable) {
                $plan->blocked[$handle] = $change->restoreBlocker ?? Craft::t('visorr', 'Cannot be restored.');
                continue;
            }

            $plan->handles[] = $handle;
            $plan->changes[] = $change;
        }

        return $plan;
    }

    /**
     * Restore the planned fields onto the canonical element.
     *
     * The save creates a revision of its own, so a restore is itself undoable — which is the
     * property that makes it safe to offer at all.
     *
     * @throws InvalidElementException if the element will not save
     */
    public function apply(RestorePlan $plan, ?string $note = null): ElementInterface
    {
        $canonical = $plan->canonical;
        $revision = $plan->revision;

        if ($canonical === null || $revision === null) {
            throw new InvalidArgumentException('A restore plan needs both a canonical element and a revision.');
        }

        if ($plan->handles === []) {
            return $canonical;
        }

        $layout = $canonical->getFieldLayout();
        $fields = [];

        if ($layout !== null) {
            foreach ($layout->getCustomFields() as $field) {
                $fields[$field->handle] = $field;
            }
        }

        foreach ($plan->handles as $handle) {
            if (in_array($handle, self::ATTRIBUTES, true)) {
                $canonical->$handle = $revision->$handle;
                continue;
            }

            $field = $fields[$handle] ?? null;

            if ($field === null) {
                continue;
            }

            $this->copyField($field, $revision, $canonical);
        }

        $canonical->setRevisionNotes($note ?? $this->defaultNote($plan));

        if (!Craft::$app->getElements()->saveElement($canonical)) {
            throw new InvalidElementException(
                $canonical,
                'Could not restore: ' . implode('; ', $canonical->getErrorSummary(true))
            );
        }

        return $canonical;
    }

    /**
     * Craft's own whole-element revert, wrapped so callers have one service to talk to.
     */
    public function revertAll(ElementInterface $revision, ?int $userId = null): ElementInterface
    {
        $userId ??= (int)Craft::$app->getUser()->getId();

        return Craft::$app->getRevisions()->revertToRevision($revision, $userId);
    }

    /**
     * Copy one field's value from the revision onto the canonical.
     */
    private function copyField(FieldInterface $field, ElementInterface $revision, ElementInterface $canonical): void
    {
        try {
            $value = $revision->getFieldValue($field->handle);
        } catch (Throwable $e) {
            Craft::warning(
                "Visorr could not read field {$field->handle} on revision #{$revision->id}: {$e->getMessage()}",
                Plugin::LOG_CATEGORY
            );
            return;
        }

        $nested = ValueFormatter::nestedElements($value);

        if ($nested !== null || $field instanceof ElementContainerFieldInterface) {
            $canonical->setFieldValue($field->handle, $this->rekeyNested($field, $value, $revision));
            return;
        }

        $canonical->setFieldValue($field->handle, $field->serializeValue($value, $revision));
    }

    /**
     * Turn a nested field's serialized value into "these are all new blocks".
     *
     * @return array<string, mixed>
     */
    private function rekeyNested(FieldInterface $field, mixed $value, ElementInterface $revision): array
    {
        $serialized = $field->serializeValue($value, $revision);

        if (!is_array($serialized)) {
            return [];
        }

        $rekeyed = [];
        $index = 0;

        foreach ($serialized as $block) {
            $rekeyed['new' . (++$index)] = $block;
        }

        return $rekeyed;
    }

    private function attributeChange(string $handle, ElementInterface $canonical, ElementInterface $revision): FieldDiff
    {
        $change = new FieldDiff([
            'handle' => $handle,
            'label' => ucfirst($handle),
            'kind' => FieldDiff::KIND_ATTRIBUTE,
            'oldText' => ValueFormatter::text($canonical->$handle ?? null),
            'newText' => ValueFormatter::text($revision->$handle ?? null),
        ]);

        $change->changed = $change->oldText !== $change->newText;

        return $change;
    }

    private function defaultNote(RestorePlan $plan): string
    {
        $info = $plan->revisionInfo;
        $num = $info?->num ?? 0;
        $labels = [];

        foreach ($plan->changes as $change) {
            $labels[] = $change->label;
        }

        return Craft::t('visorr', 'Restored {fields} from revision {num}.', [
            'fields' => implode(', ', $labels),
            'num' => $num,
        ]);
    }
}
