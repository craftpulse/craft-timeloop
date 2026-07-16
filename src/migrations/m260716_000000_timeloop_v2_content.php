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
use Throwable;
use yii\base\InvalidConfigException;
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
 * - **Not transactional, by design:** this overrides `up()`/`down()` directly
 *   instead of `safeUp()`/`safeDown()`, so the pass does *not* run inside a
 *   single wrapping transaction. The pass is idempotent and the field's
 *   read-time normalizer is the safety net for any row it misses, so wrapping
 *   millions of `elements_sites` rows in one transaction buys no correctness,
 *   only undo-log growth and lock contention for the whole upgrade window.
 *   Progress is echoed every 1,000 updated rows.
 * - **Skip-on-error:** a value that fails to normalize (a malformed date, an
 *   unparseable legacy shape, ...) is left as-is and logged via
 *   `Craft::warning()` with the `elements_sites` row ID and layout-element UID,
 *   rather than aborting the whole migration. That row keeps rendering through
 *   the read-time normalizer, which applies the same best-effort mapping on
 *   every request; a value that fails here will also fail there, so nothing is
 *   silently lost, only deferred. A field-layout element whose field reference
 *   is dangling (a deleted Timeloop field never pruned from a layout) is
 *   likewise skipped rather than fataling the whole run.
 * - **Deferred, not lossless:** most legacy `frequency`/`cycle`/`days`/
 *   `timestring`/end-date/times/reminder values are mapped in full, and the
 *   4.1.1/beta.3 #62 end-time bug is repaired in passing (the RRULE `UNTIL`
 *   takes the end time, not the buggy stored end date; see the
 *   {@see ValueNormalizer} docblock for the month-end (`BYMONTHDAY=-1`)
 *   decision). Two shapes are *not* rewritten by this pass: a value still
 *   wrapped as a JSON string (rather than a native array) is left untouched
 *   and upgraded on first read instead, and a value with no resolvable
 *   `dtstart` is stored as `null`, matching what the read path renders for an
 *   empty value.
 *
 * **The upgrade is one-way.** Once this migration (or the read-time
 * normalizer) has rewritten a value to the v2 shape, downgrading the plugin to
 * 5.0.0 reads every migrated value as empty: the 5.0.0 field only understands
 * the legacy `loopStartDate`/`loopEndDate`/`loopPeriod`/... keys, none of which
 * exist in a v2 value. There is no supported downgrade path after this
 * migration has run.
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
     *
     * Intentionally not transactional; see the class docblock.
     */
    public function up(bool $throwExceptions = false): bool
    {
        try {
            $layoutElementUids = $this->_timeloopLayoutElementUids();

            if ($layoutElementUids !== []) {
                $this->_upgradeContent($layoutElementUids);
            }
        } catch (Throwable $e) {
            echo 'Exception: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n";
            echo $e->getTraceAsString() . "\n";

            if ($throwExceptions) {
                throw $e;
            }

            return false;
        }

        $this->afterUp();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function down(bool $throwExceptions = false): bool
    {
        echo "m260716_000000_timeloop_v2_content cannot be reverted.\n";

        return false;
    }

    // Private Methods
    // =========================================================================

    /**
     * Walks `elements_sites` and upgrades every Timeloop value it finds.
     *
     * A value that fails to normalize is caught and skipped per-value (see the
     * class docblock); this only documents exceptions that are *not* caught
     * here, i.e. failures unrelated to a single value (the timezone lookup, the
     * query itself, or the row `UPDATE`), which propagate to {@see up()}.
     *
     * @param string[] $layoutElementUids Every field-layout-element UID that maps to a Timeloop field.
     * @return void
     * @throws \Exception if the system time zone is invalid.
     * @throws \yii\db\Exception if a row update fails.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _upgradeContent(array $layoutElementUids): void
    {
        $timezone = new DateTimeZone(Craft::$app->getTimeZone());

        $query = (new Query())
            ->select(['id', 'content'])
            ->from(['s' => '{{%elements_sites}}'])
            ->where(['not', ['content' => null]])
            ->orderBy(['id' => SORT_ASC]);

        $updated = 0;

        foreach (Db::each($query) as $row) {
            $content = is_string($row['content']) ? Json::decodeIfJson($row['content']) : $row['content'];

            if (!is_array($content)) {
                continue;
            }

            $changed = false;

            foreach ($layoutElementUids as $uid) {
                if (!array_key_exists($uid, $content) || !ValueNormalizer::needsUpgrade($content[$uid])) {
                    continue;
                }

                try {
                    $v2 = ValueNormalizer::normalize((array)$content[$uid], $timezone);
                } catch (Throwable $e) {
                    Craft::warning(
                        sprintf(
                            'Skipping unnormalizable Timeloop value on elements_sites row %s (layout element %s): %s',
                            $row['id'],
                            $uid,
                            $e->getMessage(),
                        ),
                        __METHOD__,
                    );

                    continue;
                }

                $content[$uid] = $v2['dtstart'] === null ? null : $v2;
                $changed = true;
            }

            if (!$changed) {
                continue;
            }

            $this->update('{{%elements_sites}}', ['content' => new JsonExpression($content)], ['id' => $row['id']], [], false);
            $updated++;

            if ($updated % 1000 === 0) {
                echo "    > upgraded {$updated} rows so far\n";
            }
        }

        echo "    > upgraded {$updated} row(s) total\n";
    }

    /**
     * Returns every field-layout-element UID that maps to a Timeloop field.
     *
     * A single Timeloop field can appear in many layouts (entry types, Matrix
     * block types, users, ...); each usage has its own layout-element UID, and
     * that UID is the key the value is stored under. A layout element whose
     * field reference is dangling (a deleted Timeloop field never pruned from
     * a layout) is skipped rather than allowed to fatal the whole migration.
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
                if (!$element instanceof CustomField) {
                    continue;
                }

                try {
                    $field = $element->getField();
                } catch (InvalidConfigException) {
                    continue;
                }

                if ($field instanceof TimeloopField) {
                    $uids[$element->uid] = $element->uid;
                }
            }
        }

        return array_values($uids);
    }
}
