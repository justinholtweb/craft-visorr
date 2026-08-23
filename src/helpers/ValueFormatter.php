<?php

namespace justinholtweb\visorr\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\base\NestedElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\ElementCollection;
use craft\fields\data\ColorData;
use craft\fields\data\LinkData;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\SingleOptionFieldData;
use craft\helpers\DateTimeHelper;
use DateTimeInterface;
use Throwable;

/**
 * Turns a field value into the two things a comparison needs: something to *compare* and
 * something to *read*.
 *
 * They are deliberately different. Equality is decided from a **signature** — a normalised,
 * structural representation — because a field's readable form throws information away and two
 * genuinely different values can render identically. The readable form is then only used to
 * show a human what moved.
 *
 * ## Why signatures are not just `serializeValue()`
 *
 * They mostly are, and where they are, that is the right answer: a relation field serializes to
 * the IDs of the elements it points at, and those IDs are stable across revisions because they
 * point at real, shared elements.
 *
 * Nested-element fields are the exception, and the reason this class exists. A revision is a
 * *duplicate* of the element, so every Matrix block inside it is a brand-new entry with a
 * brand-new ID. `Matrix::serializeValue()` keys its output by those IDs, so comparing two
 * revisions of an unchanged Matrix field by their serialized values reports every block as
 * changed, every time. {@see signature()} therefore recurses through nested elements itself,
 * keyed by position rather than ID, and asks the same question of each block's own fields.
 */
abstract class ValueFormatter
{
    /**
     * How deep to recurse into nested elements before giving up. Matrix inside Matrix inside
     * Matrix is already an unusual model; six levels is well past anything real, and the guard
     * is here so a cyclic structure cannot hang a comparison screen.
     */
    private const MAX_DEPTH = 6;

    /**
     * A normalised, comparable representation of one field's value on one element.
     *
     * Two elements' values are the same field value if and only if their signatures are equal.
     *
     * @return mixed Arrays, scalars and null only — always JSON-encodable.
     */
    public static function signature(FieldInterface $field, ElementInterface $element, int $depth = 0): mixed
    {
        try {
            $value = $element->getFieldValue($field->handle);
        } catch (Throwable) {
            // A field whose value cannot be loaded — a plugin uninstalled since the revision
            // was written, most often — must not take the whole comparison down with it.
            return ['__visorr_unreadable' => $field->handle];
        }

        return self::signatureOfValue($value, $field, $element, $depth);
    }

    /**
     * @return mixed
     */
    public static function signatureOfValue(
        mixed $value,
        FieldInterface $field,
        ElementInterface $element,
        int $depth = 0,
    ): mixed {
        if ($depth >= self::MAX_DEPTH) {
            return '__visorr_max_depth';
        }

        $nested = self::nestedElements($value);

        if ($nested !== null) {
            return array_map(
                fn(NestedElementInterface $child) => self::elementSignature($child, $depth + 1),
                $nested
            );
        }

        try {
            $serialized = $field->serializeValue($value, $element);
        } catch (Throwable) {
            return ['__visorr_unreadable' => $field->handle];
        }

        return self::normalize($serialized);
    }

    /**
     * The structural signature of a nested element: what type it is, whether it is on, and what
     * its own fields say. No IDs, because a revision's nested elements are duplicates and their
     * IDs are new every time.
     *
     * @return array<string, mixed>
     */
    public static function elementSignature(ElementInterface $element, int $depth = 0): array
    {
        $signature = [
            'type' => self::typeHandle($element),
            'enabled' => (bool)$element->enabled,
        ];

        if ($element::hasTitles()) {
            $signature['title'] = (string)$element->title;
        }

        $layout = $element->getFieldLayout();

        if ($layout !== null) {
            $fields = [];
            foreach ($layout->getCustomFields() as $field) {
                $fields[$field->handle] = self::signature($field, $element, $depth + 1);
            }
            $signature['fields'] = $fields;
        }

        return $signature;
    }

    /**
     * A readable, plain-text rendering of a value, for the diff itself.
     *
     * Plain text, never markup: the result is escaped by {@see TextDiff::toHtml()}, so anything
     * tag-shaped that survives to here is shown as the text it is.
     */
    public static function text(mixed $value, ?FieldInterface $field = null, ?ElementInterface $element = null): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? Craft::t('visorr', 'On') : Craft::t('visorr', 'Off');
        }

        if (is_scalar($value)) {
            return self::htmlToText((string)$value);
        }

        if ($value instanceof DateTimeInterface) {
            $date = DateTimeHelper::toDateTime($value);

            return $date !== false ? $date->format('Y-m-d H:i:s') : '';
        }

        if ($value instanceof SingleOptionFieldData) {
            return (string)($value->label ?? $value->value ?? '');
        }

        if ($value instanceof MultiOptionsFieldData) {
            $labels = [];
            foreach ($value as $option) {
                $labels[] = (string)($option->label ?? $option->value ?? '');
            }
            return implode(', ', $labels);
        }

        if ($value instanceof ColorData) {
            return (string)$value->getHex();
        }

        if ($value instanceof LinkData) {
            $label = $value->getLabel();
            $url = (string)$value;
            return $label !== '' && $label !== $url ? "$label — $url" : $url;
        }

        $elements = self::elementList($value);

        if ($elements !== null) {
            return implode("\n", array_map(
                fn(ElementInterface $item) => self::elementLabel($item),
                $elements
            ));
        }

        if (is_array($value)) {
            return self::arrayToText($value);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return self::htmlToText((string)$value);
        }

        if ($field !== null && $element !== null) {
            try {
                return self::arrayToText((array)$field->serializeValue($value, $element));
            } catch (Throwable) {
                // fall through
            }
        }

        return '';
    }

    /**
     * A short one-line label for an element, used wherever a value is a list of elements.
     */
    public static function elementLabel(ElementInterface $element): string
    {
        $label = (string)$element->getUiLabel();

        if ($label === '') {
            $label = Craft::t('visorr', 'Untitled');
        }

        // Related elements are shared, so their IDs are stable across revisions and worth
        // showing: "the same three assets, reordered" should read differently from "three
        // different assets".
        return $element->id !== null ? sprintf('%s (#%d)', $label, $element->id) : $label;
    }

    /**
     * Flatten markup to readable text, keeping the block structure that makes prose diffable.
     *
     * Rich text is stored as HTML. Diffing the raw markup means a wrapper class change reads as
     * a rewritten paragraph, and every diff is drowned in tags. Diffing the text alone loses
     * paragraph boundaries and glues sentences together. Converting block-level tags to
     * newlines first gets both.
     */
    public static function htmlToText(string $html): string
    {
        if (!str_contains($html, '<')) {
            return $html;
        }

        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        // Block-level closings that separate *ideas* get a blank line; ones that separate
        // *items* get a single newline. The distinction is what makes a paragraph rewrite and
        // a list reorder look different in the diff.
        $text = preg_replace(
            '#</(p|div|h[1-6]|blockquote|figure|section|article)\s*>#i',
            "\n\n",
            $text
        ) ?? $text;
        $text = preg_replace('#</(li|tr|td|th|figcaption|dt|dd)\s*>#i', "\n", $text) ?? $text;
        $text = preg_replace('#<li\b[^>]*>#i', '• ', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Tidy the whitespace the substitutions above leave behind. Source formatting —
        // indented markup, newlines between tags — must not show up as an edit, so spaces and
        // tabs either side of a newline go, and runs of blank lines collapse to one. Blank
        // lines themselves survive: they are the paragraph breaks put there two lines up.
        $text = preg_replace('/[ \t]*\n[ \t]*/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * The elements behind a value, if it is a list of them; null if it is not.
     *
     * Resolving an element query costs a database round trip, which is why this is only asked
     * of values that are already queries — and why the result is passed around rather than
     * asked for twice.
     *
     * @return ElementInterface[]|null
     */
    public static function elementList(mixed $value): ?array
    {
        if ($value instanceof ElementQueryInterface) {
            /** @var ElementInterface[] $all */
            $all = $value->status(null)->all();
            return $all;
        }

        if ($value instanceof ElementCollection) {
            /** @var ElementInterface[] $all */
            $all = $value->all();
            return $all;
        }

        return null;
    }

    /**
     * The nested elements behind a value, if this is a nested-element field; null if it is not.
     *
     * The distinction that matters is ownership. A relation field points at elements that exist
     * independently, so their IDs mean something. A nested-element field *owns* its elements,
     * so a revision has its own copies of them with new IDs, and those IDs mean nothing.
     *
     * @return NestedElementInterface[]|null
     */
    public static function nestedElements(mixed $value): ?array
    {
        $elements = self::elementList($value);

        if ($elements === null || $elements === []) {
            // An empty relation field and an empty Matrix field both serialize to nothing, so
            // treating "empty" as "not nested" costs nothing and avoids a needless query.
            return null;
        }

        foreach ($elements as $element) {
            if (!$element instanceof NestedElementInterface) {
                return null;
            }
        }

        /** @var NestedElementInterface[] $elements */
        return $elements;
    }

    /**
     * The handle of an element's entry type / group / volume, when it has one. Used to label
     * a nested block as what the author knows it as.
     */
    public static function typeHandle(ElementInterface $element): string
    {
        if (method_exists($element, 'getType')) {
            try {
                $type = $element->getType();
                if (is_object($type) && property_exists($type, 'handle')) {
                    return (string)$type->handle;
                }
            } catch (Throwable) {
                // Not every getType() is an entry type, and not every element has one set.
            }
        }

        return $element::refHandle() ?? $element::lowerDisplayName();
    }

    /**
     * Keys that identify *which row* a value is, rather than what it says.
     *
     * A revision is a duplicate, so anything keyed to the element or to its own storage row
     * necessarily differs between a revision and its canonical. Left in, they make every such
     * field report a change on every comparison.
     */
    private const IDENTITY_KEYS = [
        'id', 'elementId', 'ownerId', 'primaryOwnerId', 'uid',
        'dateCreated', 'dateUpdated', 'dateDeleted',
    ];

    /**
     * Keys whose presence marks a map as a stored record rather than authored content.
     */
    private const RECORD_MARKERS = [
        'id', 'elementId', 'siteId', 'fieldId', 'ownerId', 'primaryOwnerId', 'dateCreated', 'dateUpdated', 'uid',
    ];

    /**
     * Recursively coerce a serialized value into something JSON-comparable.
     *
     * `JSON_PRESERVE_ZERO_FRACTION` is not used here because nothing is encoded — but the same
     * hazard is why floats are left alone rather than cast: a value that compares equal must
     * not be made unequal by this function.
     */
    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('c');
        }

        if (is_string($value)) {
            return self::stripEmbeddedElementIds($value);
        }

        if (is_array($value)) {
            $normalized = [];
            foreach (self::stripIdentity($value) as $key => $item) {
                $normalized[$key] = self::normalize($item);
            }
            return $normalized;
        }

        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                try {
                    return self::normalize($value->toArray());
                } catch (Throwable) {
                    // fall through to __toString
                }
            }

            if (method_exists($value, '__toString')) {
                return (string)$value;
            }

            return get_class($value);
        }

        return $value;
    }

    /**
     * @param array<mixed> $value
     */
    private static function arrayToText(array $value): string
    {
        // A Table field is a list of rows of cells; rendering it as tab-separated lines reads
        // far better in a diff than JSON, and diffs cell by cell rather than brace by brace.
        if (self::isRowList($value)) {
            $lines = [];
            foreach ($value as $row) {
                $cells = array_map(
                    fn($cell) => is_scalar($cell) || $cell === null ? (string)$cell : '…',
                    (array)$row
                );
                $lines[] = implode("\t", $cells);
            }
            return implode("\n", $lines);
        }

        if (self::isFlatScalarList($value)) {
            return implode(', ', array_map(fn($item) => (string)$item, $value));
        }

        $json = json_encode(self::normalize($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return $json !== false ? $json : '';
    }

    /**
     * Drop the identity keys from a map that is plainly a stored record.
     *
     * The two-marker test is the safety catch. A field that stores a user-entered value under a
     * key called `id` — a video ID, an external reference — is a real thing, and stripping it
     * would hide a genuine edit. A map carrying an `id` *and* an `elementId`, or a `fieldId`
     * and a `dateUpdated`, is not that: it is a row.
     *
     * Found against a third-party address field, whose serialized value carries its own row ID
     * and the ID of the element it belongs to. Both change when the element is duplicated into
     * a revision, so every comparison reported the address as edited while showing identical
     * text on both sides.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function stripIdentity(array $value): array
    {
        if (array_is_list($value)) {
            return $value;
        }

        $markers = 0;

        foreach (self::RECORD_MARKERS as $marker) {
            if (array_key_exists($marker, $value)) {
                $markers++;
            }
        }

        if ($markers < 2) {
            return $value;
        }

        foreach (self::IDENTITY_KEYS as $key) {
            unset($value[$key]);
        }

        return $value;
    }

    /**
     * Blank out nested-element IDs embedded in markup.
     *
     * A rich-text field that owns nested entries writes their IDs into its HTML. Those entries
     * are duplicated along with the revision, so the IDs differ on every side of every
     * comparison. Replacing them with a placeholder compares the *structure* — which is all
     * that can be compared here anyway, since the blocks' own content lives on other elements.
     */
    private static function stripEmbeddedElementIds(string $value): string
    {
        if (!str_contains($value, 'data-entry-id') && !str_contains($value, 'data-element-id')) {
            return $value;
        }

        return preg_replace('/\bdata-(entry|element)-id="\d+"/', 'data-$1-id="#"', $value) ?? $value;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isRowList(array $value): bool
    {
        if ($value === [] || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $row) {
            if (!is_array($row)) {
                return false;
            }
            foreach ($row as $cell) {
                if ($cell !== null && !is_scalar($cell)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isFlatScalarList(array $value): bool
    {
        if ($value === [] || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if ($item !== null && !is_scalar($item)) {
                return false;
            }
        }

        return true;
    }
}
