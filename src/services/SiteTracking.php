<?php

namespace justinholtweb\visorr\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\helpers\Db;
use DateTime;
use justinholtweb\visorr\db\Table;
use justinholtweb\visorr\models\RevisionInfo;
use justinholtweb\visorr\models\Settings;
use justinholtweb\visorr\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Per-site revision history for content that is shared across sites.
 *
 * ## The problem this solves
 *
 * A Single with `propagationMethod: all` has one canonical element serving every site. Craft's
 * revision history is keyed on that canonical, so it is one undifferentiated list: an editor
 * working on the German site opens the history of their homepage and sees thirty entries, of
 * which four are theirs and twenty-six were made by people editing a page they have never seen.
 * Reverting to any of the others silently replaces their content with someone else's.
 *
 * This was found the hard way on a real multi-site build, and the fix shipped there as a
 * bespoke module. This is that module, generalised past the one section it was written for.
 *
 * ## How it works
 *
 * When a revision is created, the site the author was working in is recorded. When the control
 * panel asks for an element's revisions, the query is narrowed to the requested site. Revisions
 * from before Visorr was installed have no recorded site; they surface on the primary site by
 * default, because the alternative — showing them nowhere — looks exactly like data loss.
 *
 * **Recording happens in every edition.** Only the filtering is a Pro feature. A site that
 * upgrades later should find the history already sorted, not start from the day it paid.
 */
class SiteTracking extends Component
{
    /**
     * Record which site a revision was authored from.
     *
     * The site is taken from the control-panel request rather than from the element, because
     * the element being saved is the *canonical*, and on a shared Single its `siteId` is
     * whichever site Craft happened to load it in — not the one the author was looking at.
     */
    public function record(int $revisionId, ElementInterface $canonical): void
    {
        try {
            Craft::$app->getDb()->createCommand()->upsert(Table::REVISION_SITES, [
                'revisionId' => $revisionId,
                'siteId' => $this->currentSiteId($canonical),
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
            ])->execute();
        } catch (Throwable $e) {
            // Tracking is an enhancement. A site that cannot write this row still has working
            // revisions, and a save that failed because of an audit table would be worse than
            // one whose history is not filtered.
            Craft::warning("Visorr could not record the site for revision #$revisionId: {$e->getMessage()}", Plugin::LOG_CATEGORY);
        }
    }

    /**
     * Whether revision history should be filtered by site for this element.
     */
    public function applies(?ElementInterface $canonical): bool
    {
        if ($canonical === null || !Plugin::getInstance()->isPro()) {
            return false;
        }

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->siteFilterEnabled || !Craft::$app->getIsMultiSite()) {
            return false;
        }

        return match ($settings->siteFilterMode) {
            Settings::SITE_FILTER_ALL => true,
            Settings::SITE_FILTER_SELECTED => $this->isSelectedSection($canonical, $settings),
            default => $this->isSharedAcrossSites($canonical),
        };
    }

    /**
     * The revision *element* IDs that belong to a site, for narrowing an element query.
     *
     * Returns `[0]` rather than `[]` when nothing matches: an `IN ()` with an empty list is a
     * syntax error in some drivers and a match-everything in careless query builders, and
     * "this site has no revisions" must not read as "show all of them".
     *
     * @return int[]
     */
    public function allowedElementIds(int $canonicalId, int $siteId): array
    {
        $allowed = array_map('intval', (new Query())
            ->select(['e.id'])
            ->from(['rs' => Table::REVISION_SITES])
            ->innerJoin(['r' => CraftTable::REVISIONS], '[[r.id]] = [[rs.revisionId]]')
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.revisionId]] = [[r.id]]')
            ->where(['rs.siteId' => $siteId, 'r.canonicalId' => $canonicalId])
            ->column());

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->siteFilterLegacyOnPrimary && $siteId === Craft::$app->getSites()->getPrimarySite()->id) {
            $untracked = array_map('intval', (new Query())
                ->select(['e.id'])
                ->from(['r' => CraftTable::REVISIONS])
                ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.revisionId]] = [[r.id]]')
                ->leftJoin(['rs' => Table::REVISION_SITES], '[[rs.revisionId]] = [[r.id]]')
                ->where(['r.canonicalId' => $canonicalId, 'rs.revisionId' => null])
                ->column());

            $allowed = array_merge($allowed, $untracked);
        }

        return $allowed !== [] ? $allowed : [0];
    }

    /**
     * Narrow a list of revision metadata to one site.
     *
     * @param RevisionInfo[] $infos
     * @return RevisionInfo[]
     */
    public function filterInfos(array $infos, int $siteId): array
    {
        if (!Plugin::getInstance()->isPro()) {
            return $infos;
        }

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->siteFilterEnabled) {
            return $infos;
        }

        $isPrimary = $siteId === Craft::$app->getSites()->getPrimarySite()->id;

        return array_values(array_filter($infos, function(RevisionInfo $info) use ($siteId, $isPrimary, $settings) {
            if ($info->siteId === null) {
                return $settings->siteFilterLegacyOnPrimary && $isPrimary;
            }

            return $info->siteId === $siteId;
        }));
    }

    /**
     * How many revisions each site holds for one element — the summary on the panel.
     *
     * @return array<int, int> site ID => count, plus key 0 for untracked
     */
    public function countsBySite(int $canonicalId): array
    {
        $rows = (new Query())
            ->select([
                'siteId' => 'rs.siteId',
                'total' => 'COUNT(*)',
            ])
            ->from(['r' => CraftTable::REVISIONS])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.revisionId]] = [[r.id]]')
            ->leftJoin(['rs' => Table::REVISION_SITES], '[[rs.revisionId]] = [[r.id]]')
            ->where(['r.canonicalId' => $canonicalId, 'e.dateDeleted' => null])
            ->groupBy(['rs.siteId'])
            ->all();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int)($row['siteId'] ?? 0)] = (int)$row['total'];
        }

        return $counts;
    }

    /**
     * Backfill the authoring site for revisions written before Visorr was installed.
     *
     * There is no way to know which site those were authored from — the information was never
     * recorded — so this does not guess. It assigns them to a site you name, which is the
     * honest version of the feature: an administrator who knows their own history can say "all
     * of these were ours", and everyone else can leave them alone.
     *
     * @return int Rows written.
     */
    public function backfill(int $siteId, ?int $canonicalId = null): int
    {
        $query = (new Query())
            ->select(['r.id'])
            ->from(['r' => CraftTable::REVISIONS])
            ->leftJoin(['rs' => Table::REVISION_SITES], '[[rs.revisionId]] = [[r.id]]')
            ->where(['rs.revisionId' => null]);

        if ($canonicalId !== null) {
            $query->andWhere(['r.canonicalId' => $canonicalId]);
        }

        $revisionIds = array_map('intval', $query->column());

        if ($revisionIds === []) {
            return 0;
        }

        $now = Db::prepareDateForDb(new DateTime());
        $rows = array_map(fn(int $id) => [$id, $siteId, $now], $revisionIds);

        Craft::$app->getDb()->createCommand()
            ->batchInsert(Table::REVISION_SITES, ['revisionId', 'siteId', 'dateCreated'], $rows)
            ->execute();

        return count($rows);
    }

    /**
     * The site the current request is editing in.
     */
    private function currentSiteId(ElementInterface $fallback): int
    {
        $request = Craft::$app->getRequest();

        // `Cp::requestedSite()` is web-only; a resave job or a console command has no requested
        // site at all, and asking for one there is a fatal, not a miss.
        if (!$request->getIsConsoleRequest()) {
            $site = Cp::requestedSite();

            if ($site !== null) {
                return (int)$site->id;
            }
        }

        return (int)($fallback->siteId ?? Craft::$app->getSites()->getPrimarySite()->id);
    }

    private function isSharedAcrossSites(ElementInterface $element): bool
    {
        try {
            return count($element->getSupportedSites()) > 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function isSelectedSection(ElementInterface $element, Settings $settings): bool
    {
        if (!$element instanceof Entry) {
            return false;
        }

        try {
            $uid = $element->getSection()?->uid;
        } catch (Throwable) {
            return false;
        }

        return $uid !== null && in_array($uid, $settings->siteFilterSectionUids, true);
    }
}
