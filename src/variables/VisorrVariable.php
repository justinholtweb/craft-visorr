<?php

namespace justinholtweb\visorr\variables;

use craft\base\ElementInterface;
use justinholtweb\visorr\models\EffectiveRetention;
use justinholtweb\visorr\models\RevisionDiff;
use justinholtweb\visorr\models\RevisionInfo;
use justinholtweb\visorr\Plugin;

/**
 * `craft.visorr` — read-only access to revision history from templates.
 *
 * Read-only on purpose. Nothing here deletes, restores or pins: a template rendering a page is
 * not the place to change history, and a Twig variable that could would be reachable from any
 * front-end template on the site.
 */
class VisorrVariable
{
    /**
     * Revision metadata for an element.
     *
     * @return RevisionInfo[]
     */
    public function history(ElementInterface $element, ?int $limit = null, ?int $siteId = null): array
    {
        return Plugin::getInstance()->revisions->getRevisionInfos(
            $element->getCanonical(),
            $siteId,
            $limit,
        );
    }

    public function count(ElementInterface $element): int
    {
        return Plugin::getInstance()->revisions->countFor((int)$element->getCanonical()->id);
    }

    /**
     * Compare two versions of an element. Pass revision IDs, or 0 for "as it is now".
     */
    public function compare(ElementInterface $element, int $leftRevisionId, int $rightRevisionId = 0): ?RevisionDiff
    {
        $plugin = Plugin::getInstance();
        $canonical = $element->getCanonical();

        $left = $this->resolveSide($canonical, $leftRevisionId);
        $right = $this->resolveSide($canonical, $rightRevisionId);

        if ($left === null || $right === null) {
            return null;
        }

        return $plugin->comparison->compare($left, $right);
    }

    /**
     * The retention rule in force for an element, and why.
     */
    public function retention(ElementInterface $element): EffectiveRetention
    {
        return Plugin::getInstance()->retention->forElement($element->getCanonical());
    }

    public function isPinned(int $revisionId): bool
    {
        return Plugin::getInstance()->pins->isPinned($revisionId);
    }

    /**
     * Estimated storage taken by an element's revision history, in bytes.
     */
    public function bytes(ElementInterface $element): int
    {
        $plugin = Plugin::getInstance();
        $infos = $plugin->revisions->getRevisionInfos($element->getCanonical(), null, null, true);

        return array_sum(array_map(fn(RevisionInfo $info) => $info->sizeBytes, $infos));
    }

    private function resolveSide(ElementInterface $canonical, int $revisionId): ?ElementInterface
    {
        if ($revisionId === 0) {
            return $canonical;
        }

        $plugin = Plugin::getInstance();
        $info = $plugin->revisions->getRevisionInfo($revisionId);

        if ($info === null || $info->canonicalId !== (int)$canonical->id) {
            return null;
        }

        return $plugin->revisions->getRevisionElement($info, $canonical->siteId);
    }
}
