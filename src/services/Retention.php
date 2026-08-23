<?php

namespace justinholtweb\visorr\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use justinholtweb\visorr\models\EffectiveRetention;
use justinholtweb\visorr\models\RetentionPolicy;
use justinholtweb\visorr\models\Settings;
use justinholtweb\visorr\Plugin;
use yii\base\Component;

/**
 * Decides how much history a given piece of content is allowed to keep.
 *
 * This is the authority. Nothing else in the plugin has an opinion about what "too many
 * revisions" means — {@see Pruning} asks, and acts on the answer.
 *
 * Resolution is most-specific-wins across three axes: element type, section, site. A site can
 * therefore say "ten everywhere, two hundred on the homepage, and never expire anything on the
 * legal section" without the rules fighting each other, and {@see EffectiveRetention::$source}
 * names whichever rule won so the answer is explicable.
 *
 * With no policy matching, the fallback is Craft's own `maxRevisions`. Visorr is a layer over
 * Craft's behaviour, not a replacement for it, and a site that uninstalls the plugin should
 * find its history governed by the same number it was before.
 */
class Retention extends Component
{
    /** @var RetentionPolicy[]|null */
    private ?array $_policies = null;

    /** @var array<string, EffectiveRetention> */
    private array $_resolved = [];

    /**
     * Every enabled policy, most specific first.
     *
     * @return RetentionPolicy[]
     */
    public function policies(): array
    {
        if ($this->_policies !== null) {
            return $this->_policies;
        }

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        $policies = array_values(array_filter(
            $settings->getPolicies(),
            fn(RetentionPolicy $policy) => $policy->enabled
        ));

        usort(
            $policies,
            fn(RetentionPolicy $a, RetentionPolicy $b) => $b->specificity() <=> $a->specificity()
        );

        return $this->_policies = $policies;
    }

    /**
     * Every policy, enabled or not, in the order the settings screen shows them.
     *
     * @return RetentionPolicy[]
     */
    public function allPolicies(): array
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return $settings->getPolicies();
    }

    /**
     * The rule in force for a kind of content.
     */
    public function resolve(string $elementType, ?string $sectionUid = null, ?int $siteId = null): EffectiveRetention
    {
        $cacheKey = implode('|', [$elementType, $sectionUid ?? '-', $siteId ?? '-']);

        if (isset($this->_resolved[$cacheKey])) {
            return $this->_resolved[$cacheKey];
        }

        // Retention policies are the Pro half of the plugin. In Lite, Craft's own setting is
        // still honoured — Lite never *loosens* what Craft was already doing, it just has no
        // opinion of its own.
        if (Plugin::getInstance()->isPro()) {
            $siteUid = $siteId !== null ? Craft::$app->getSites()->getSiteById($siteId)?->uid : null;

            foreach ($this->policies() as $policy) {
                if ($this->matches($policy, $elementType, $sectionUid, $siteUid)) {
                    return $this->_resolved[$cacheKey] = new EffectiveRetention([
                        'maxRevisions' => $policy->maxRevisions,
                        'maxAgeDays' => $policy->maxAgeDays,
                        'minKeep' => max(1, $policy->minKeep),
                        'source' => EffectiveRetention::SOURCE_POLICY,
                        'policyUid' => $policy->uid,
                        'description' => $this->describe($policy),
                    ]);
                }
            }
        }

        $craftMax = Craft::$app->getConfig()->getGeneral()->maxRevisions;

        return $this->_resolved[$cacheKey] = new EffectiveRetention([
            'maxRevisions' => $craftMax ?: null,
            'maxAgeDays' => null,
            'minKeep' => 1,
            'source' => $craftMax ? EffectiveRetention::SOURCE_CRAFT : EffectiveRetention::SOURCE_NONE,
            'description' => $craftMax
                ? Craft::t('visorr', 'Craft’s maxRevisions setting ({count})', ['count' => $craftMax])
                : Craft::t('visorr', 'No limit — Craft’s maxRevisions is disabled'),
        ]);
    }

    /**
     * The rule in force for one element.
     */
    public function forElement(ElementInterface $element): EffectiveRetention
    {
        return $this->resolve(
            get_class($element),
            $this->sectionUidFor($element),
            $element->siteId,
        );
    }

    /**
     * The section UID an element belongs to, when the idea applies to it at all.
     *
     * Nested entries — Matrix blocks — are entries with no section, which is exactly why this
     * returns null rather than throwing: they are governed by their owner's history, not their
     * own, and a policy keyed on a section should simply not match them.
     */
    public function sectionUidFor(ElementInterface $element): ?string
    {
        if (!$element instanceof Entry) {
            return null;
        }

        try {
            return $element->getSection()?->uid;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Invalidate the memoised policies — used after the settings screen saves, and by tests.
     */
    public function reset(): void
    {
        $this->_policies = null;
        $this->_resolved = [];
    }

    /**
     * A human sentence for a policy, used in the settings table and in "why is this the limit?".
     */
    public function describe(RetentionPolicy $policy): string
    {
        $parts = [];

        if ($policy->maxRevisions !== null) {
            $parts[] = Craft::t('visorr', 'keep {count} revisions', ['count' => $policy->maxRevisions]);
        }

        if ($policy->maxAgeDays !== null) {
            $parts[] = Craft::t('visorr', 'expire after {days} days', ['days' => $policy->maxAgeDays]);
        }

        if ($policy->minKeep > 1) {
            $parts[] = Craft::t('visorr', 'never fewer than {count}', ['count' => $policy->minKeep]);
        }

        return implode(', ', $parts);
    }

    private function matches(
        RetentionPolicy $policy,
        string $elementType,
        ?string $sectionUid,
        ?string $siteUid,
    ): bool {
        if ($policy->elementType !== RetentionPolicy::ANY && $policy->elementType !== $elementType) {
            return false;
        }

        if ($policy->sectionUid !== null && $policy->sectionUid !== $sectionUid) {
            return false;
        }

        if ($policy->siteUid !== null && $policy->siteUid !== $siteUid) {
            return false;
        }

        return true;
    }
}
