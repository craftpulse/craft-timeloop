<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Db;
use craft\helpers\Json;
use craftpulse\timeloop\fields\TimeloopField;
use craftpulse\timeloop\models\ValueNormalizer;
use DateTimeZone;
use yii\db\JsonExpression;

/**
 * Upgrades every stored Timeloop field value to the v2 storage shape.
 *
 * Craft 5 stores field values in `elements_sites.content` as a JSON object keyed
 * by field-layout-element UID, and every owner (entries, categories, users,
 * Matrix/Neo-nested blocks, drafts and revisions) shares that one column. This
 * migration therefore walks `elements_sites` directly rather than resaving
 * elements: a resave would skip revisions, fire element/search events for every
 * row and be far heavier, whereas a targeted content rewrite upgrades drafts,
 * revisions and nested blocks uniformly.
 *
 * Each Timeloop value is upgraded through {@see ValueNormalizer} (the same
 * mapping authority the field itself uses at read time), so the migration and
 * the read-time normalizer can never diverge. The pass is:
 *
 * - **Idempotent:** values already at v2 (`version >= 2`) are skipped, so
 *   re-running the migration is harmless and any row the migration misses is
 *   still upgraded on read by the field.
 * - **Memory-safe:** rows are streamed with {@see Db::each()} on an unbuffered
 *   connection, so the whole table is never materialized.
 * - **Lossless:** the legacy `frequency`/`cycle`/`days`/`timestring`/end-date/
 *   times/reminder are all mapped, and the 4.1.1/beta.3 #62 end-time bug is
 *   repaired in passing (the RRULE `UNTIL` takes the end time, not the buggy
 *   stored end date). See the {@see ValueNormalizer} docblock for the month-end
 *   (`BYMONTHDAY=-1`) decision.
 *
 * @author CraftPulse
 * @since 5.1.0
 */
class m260716_000000_timeloop_v2_content extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws \Exception if a stored date string cannot be parsed (via {@see ValueNormalizer::normalize()}).
     */
    public function safeUp(): bool
    {
        $layoutElementUids = $this->_timeloopLayoutElementUids();

        if ($layoutElementUids === []) {
            return true;
        }

        $timezone = new DateTimeZone(Craft::$app->getTimeZone());

        $query = (new Query())
            ->select(['id', 'content'])
            ->from(['s' => '{{%elements_sites}}'])
            ->where(['not', ['content' => null]])
            ->orderBy(['id' => SORT_ASC]);

        foreach (Db::each($query) as $row) {
            $content = is_string($row['content']) ? Json::decodeIfJson($row['content']) : $row['content'];

            if (!is_array($content)) {
                continue;
            }

            $changed = false;

            foreach ($layoutElementUids as $uid) {
                if (!array_key_exists($uid, $content) || !$this->_needsUpgrade($content[$uid])) {
                    continue;
                }

                $v2 = ValueNormalizer::normalize((array)$content[$uid], $timezone);
                $content[$uid] = $v2['dtstart'] === null ? null : $v2;
                $changed = true;
            }

            if ($changed) {
                $this->update('{{%elements_sites}}', ['content' => new JsonExpression($content)], ['id' => $row['id']], [], false);
            }
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m260716_000000_timeloop_v2_content cannot be reverted.\n";

        return false;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns every field-layout-element UID that maps to a Timeloop field.
     *
     * A single Timeloop field can appear in many layouts (entry types, Matrix
     * block types, users, ...); each usage has its own layout-element UID, and
     * that UID is the key the value is stored under.
     *
     * @return string[]
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _timeloopLayoutElementUids(): array
    {
        $uids = [];

        foreach (Craft::$app->getFields()->getAllLayouts() as $layout) {
            foreach ($layout->getCustomFieldElements() as $element) {
                if ($element instanceof CustomField && $element->getField() instanceof TimeloopField) {
                    $uids[$element->uid] = $element->uid;
                }
            }
        }

        return array_values($uids);
    }

    /**
     * Returns whether a stored value is a non-empty legacy value needing upgrade.
     *
     * @param mixed $value
     * @return bool
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _needsUpgrade(mixed $value): bool
    {
        if (!is_array($value) || $value === []) {
            return false;
        }

        return (int)($value['version'] ?? 0) < ValueNormalizer::VERSION;
    }
}
