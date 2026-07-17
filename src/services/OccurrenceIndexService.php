<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\services;

use Carbon\CarbonImmutable;
use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use craftpulse\timeloop\fields\TimeloopField;
use craftpulse\timeloop\jobs\ReindexOccurrences;
use craftpulse\timeloop\migrations\Install;
use craftpulse\timeloop\models\SettingsModel;
use craftpulse\timeloop\models\TimeloopModel;
use craftpulse\timeloop\Timeloop;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Occurrence index writes and reads.
 *
 * Maintains `{{%timeloop_occurrences}}`: a denormalized expansion of every
 * Timeloop field value into `[occurrenceStart, occurrenceEnd)` window rows, so
 * the element-query params ({@see \craftpulse\timeloop\behaviors\TimeloopQueryBehavior})
 * and the `recurringDates()` Twig function answer date-range questions without
 * expanding any recurrence at query time.
 *
 * ## What gets indexed (scope)
 *
 * Only **canonical, non-draft, non-revision** elements are indexed
 * ({@see ElementHelper::isDraftOrRevision()} covers provisional/auto-save drafts
 * and revisions). Rows are keyed by the element that *directly carries* the
 * field, its `siteId` and the field handle. For a Timeloop field nested in a
 * Matrix (or Neo) block, that element is the **nested entry**, not the owner:
 * `{@see indexElement()}` runs against whichever element the field layout belongs
 * to, so the `elementId` is always the field's own owner. The dance-school board
 * case queries top-level class entries whose Timeloop field sits directly on the
 * entry, so `elementId` equals the queried entry's id and the query behavior
 * matches it directly; querying by Matrix-nested occurrences would query the
 * nested entries, not their owners (documented limitation, not a bug).
 *
 * ## Timezone
 *
 * Occurrences are computed in the value's stored timezone and written as UTC
 * (via {@see Db::prepareDateForDb()}), the Craft convention for date columns, so
 * range comparisons in the query behavior are UTC-to-UTC. Reads convert back to
 * the value's timezone so `recurringDates()` output stays byte-identical to live
 * expansion.
 *
 * ## Holidays
 *
 * Rows are written through {@see TimeloopService::recurrenceFor()}, so the public
 * holidays resolved for the years in the write window are already subtracted: a
 * holiday landing on an occurrence produces no row. Resolution is baked at write
 * time; the nightly `timeloop/occurrences/refresh` roll re-expands in place so
 * future years' holidays and the moving horizon stay correct without a re-save.
 *
 * ## Freshness contract
 *
 * A missing or stale index must never change rendered output. Writes are always
 * a `delete + insert` of the whole `(elementId, siteId, fieldHandle)` slice, and
 * reads only trust the index within the window it actually covers: at or before
 * its last stored occurrence ({@see maxOccurrenceEnd()}) and at or after its
 * first ({@see minOccurrenceStart()}); any range reaching past either edge
 * falls back to live expansion. So an un-indexed, behind-the-horizon or
 * beyond-the-floor value renders exactly as it always did.
 *
 * ## Cost model and the write-window floor
 *
 * Every element save that touches a Timeloop field rewrites its whole index
 * slice (`delete` then `insert`, see [[_writeRows()]]), so the write cost scales
 * with the number of occurrences in the write window, not with how much of the
 * value actually changed. Two settings bound that window and so bound the
 * per-save cost: `indexHorizon` caps how far ahead a value is expanded
 * (indexing an infinite rule forever isn't possible), and `indexPastHorizon`
 * caps how far back ({@see SettingsModel::$indexPastHorizon}), so a long-running
 * series that started years ago doesn't re-expand its entire history on every
 * save. The write window is `[max(dtstart, now + indexPastHorizon), now +
 * indexHorizon]` ({@see _window()}). A series that started after the floor is
 * still indexed from its own true start; only the earliest occurrences of an
 * already-old series are left un-indexed, and reads that ask for a range
 * reaching before the floor fall back to live expansion for that element.
 *
 * @author CraftPulse
 * @since 5.1.0
 */
class OccurrenceIndexService extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var int The occurrence count above which a save-time reindex is deferred to a queue job.
     *
     * A save writes at most this many rows inline; a longer series is handed to
     * {@see ReindexOccurrences} so the save request never blocks on a large
     * expansion or insert.
     */
    public const INLINE_THRESHOLD = 200;

    /**
     * @var string The fallback expansion horizon when the plugin settings are unavailable.
     */
    private const FALLBACK_HORIZON = '+2 years';

    /**
     * @var string The fallback write-window floor when the plugin settings are unavailable.
     */
    private const FALLBACK_PAST_HORIZON = '-1 year';

    /**
     * @var int Seconds to wait for the per-slice write lock before giving up.
     *
     * A short timeout: on contention the safest move is to skip this write and
     * let the nightly refresh heal the slice, not to block the save request.
     */
    private const LOCK_TIMEOUT = 15;

    // Public Methods
    // =========================================================================

    /**
     * Handles a Timeloop field's element save, indexing inline or via a queue job.
     *
     * The single save-time entry point (called from {@see TimeloopField::afterElementSave()}).
     * Drafts and revisions are skipped; a short series is written inline; a series
     * longer than [[INLINE_THRESHOLD]] is deferred to {@see ReindexOccurrences}.
     *
     * @param ElementInterface $element The element carrying the field (the nested entry for a Matrix value).
     * @param FieldInterface $field The Timeloop field instance.
     * @return void
     * @throws \InvalidArgumentException if the rule cannot be parsed (see {@see TimeloopService::recurrenceFor()} /
     * {@see RecurrenceModel::occurrenceRows()}).
     * @throws \Exception if the value's `dtstart` or timezone cannot be parsed, or the recurrence cannot
     * otherwise be expanded (see {@see TimeloopService::recurrenceFor()} / {@see RecurrenceModel::occurrenceRows()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function handleElementSave(ElementInterface $element, FieldInterface $field): void
    {
        if (!$this->_isIndexable($element)) {
            return;
        }

        $value = $element->getFieldValue($field->handle);
        $recurrence = $value instanceof TimeloopModel ? $this->_timeloop()->recurrenceFor($value) : null;

        if (!$value instanceof TimeloopModel || $recurrence === null) {
            $this->_clear((int)$element->id, (int)$element->siteId, $field->handle);

            return;
        }

        [$from, $to] = $this->_window($value);
        $rows = $recurrence->occurrenceRows($from, $to, self::INLINE_THRESHOLD + 1);

        if (count($rows) > self::INLINE_THRESHOLD) {
            Craft::$app->getQueue()->push(new ReindexOccurrences([
                'elementId' => (int)$element->id,
            ]));

            return;
        }

        $this->_writeRows((int)$element->id, (int)$element->siteId, $field->handle, $rows);
    }

    /**
     * Indexes every Timeloop field on the given element (for its own site).
     *
     * Used by the queue job and the console commands. Each Timeloop field in the
     * element's field layout is expanded fully (no inline cap) and its slice is
     * rewritten.
     *
     * @param ElementInterface $element
     * @return void
     * @throws \Exception if a recurrence cannot be expanded (see {@see _writeField()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function indexElement(ElementInterface $element): void
    {
        if (!$this->_isIndexable($element)) {
            return;
        }

        foreach ($this->_timeloopFields($element) as $field) {
            $this->_writeField($element, $field, null);
        }
    }

    /**
     * Rebuilds the whole index from scratch: truncate, then re-index every value.
     *
     * @param ?callable $progress Optional `fn(int $done): void` progress callback.
     * @return int The number of element/site rows re-indexed.
     * @throws \Throwable if truncation or a re-index fails.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function rebuild(?callable $progress = null): int
    {
        Craft::$app->getDb()->createCommand()->truncateTable(Install::OCCURRENCES_TABLE)->execute();

        return $this->_reindex($this->_allIndexableElements(), $progress);
    }

    /**
     * Rolls the horizon forward by re-expanding every already-indexed value in place.
     *
     * Documented for a nightly cron (`timeloop/occurrences/refresh`). Unlike
     * {@see rebuild()} it does not truncate, so the index is never momentarily
     * empty on a live site; it re-expands only the element/site slices that
     * currently hold rows, which is where a moving horizon or a newly-crossed
     * year boundary (holidays) can add occurrences.
     *
     * @param ?callable $progress Optional `fn(int $done): void` progress callback.
     * @return int The number of element/site rows re-indexed.
     * @throws \Throwable if a re-index fails.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function refresh(?callable $progress = null): int
    {
        return $this->_reindex($this->_indexedElements(), $progress);
    }

    // Reads
    // =========================================================================

    /**
     * Returns the occurrence starts of a value between two dates, index-backed with a live fallback.
     *
     * Backs `recurringDates()`. When the index covers the requested range in
     * full, both edges: rows exist, their earliest occurrence starts at or
     * before `$from` ({@see minOccurrenceStart()}) and their latest reaches at
     * or past `$to` ({@see maxOccurrenceEnd()}), the starts are read straight
     * from the index, converted back to the value's timezone so the `\DateTime`
     * output is byte-identical to live expansion. Otherwise, including when the
     * requested range reaches before the index's write-window floor
     * ({@see SettingsModel::$indexPastHorizon}), or when the value is not
     * indexed at all, it falls back to live expansion so a missing, stale or
     * beyond-the-floor index never changes rendered output. Both boundaries are
     * inclusive.
     *
     * @param ElementInterface $element The element carrying the field.
     * @param TimeloopModel $value The normalized field value.
     * @param string $fieldHandle The Timeloop field handle.
     * @param DateTimeInterface $from The lower boundary (inclusive).
     * @param DateTimeInterface $to The upper boundary (inclusive).
     * @return DateTime[] The occurrence starts in the value's timezone.
     * @throws \Exception if the live fallback cannot expand the recurrence (see {@see TimeloopService::getLoop()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function occurrenceStarts(ElementInterface $element, TimeloopModel $value, string $fieldHandle, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $elementId = (int)$element->id;
        $siteId = (int)$element->siteId;
        $maxEnd = $this->maxOccurrenceEnd($elementId, $siteId, $fieldHandle);
        $minStart = $this->minOccurrenceStart($elementId, $siteId, $fieldHandle);

        if ($maxEnd !== null && $to <= $maxEnd && $minStart !== null && $from >= $minStart) {
            return $this->_readStarts($elementId, $siteId, $fieldHandle, $from, $to, new DateTimeZone($value->timezone));
        }

        $loops = $this->_timeloop()->getLoop($value, 0, false);

        return $loops === null ? [] : $this->_timeloop()->getLoopBetweenDates($loops, DateTime::createFromInterface($from), DateTime::createFromInterface($to));
    }

    /**
     * Returns the latest stored occurrence end for a value slice, or null when not indexed.
     *
     * @param int $elementId
     * @param int $siteId
     * @param string $fieldHandle
     * @return ?DateTimeImmutable The latest `occurrenceEnd` (UTC), or null.
     * @throws \Exception if the stored date cannot be parsed.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function maxOccurrenceEnd(int $elementId, int $siteId, string $fieldHandle): ?DateTimeImmutable
    {
        $max = (new Query())
            ->from(Install::OCCURRENCES_TABLE)
            ->where([
                'elementId' => $elementId,
                'siteId' => $siteId,
                'fieldHandle' => $fieldHandle,
            ])
            ->max('[[occurrenceEnd]]');

        if ($max === null || $max === false) {
            return null;
        }

        return new DateTimeImmutable((string)$max, new DateTimeZone('UTC'));
    }

    /**
     * Returns the earliest stored occurrence start for a value slice, or null when not indexed.
     *
     * The lower-bound counterpart to {@see maxOccurrenceEnd()}: a value whose
     * series started before the write-window floor ({@see SettingsModel::$indexPastHorizon})
     * has its earliest occurrences left un-indexed, so a read reaching before
     * this date must fall back to live expansion.
     *
     * @param int $elementId
     * @param int $siteId
     * @param string $fieldHandle
     * @return ?DateTimeImmutable The earliest `occurrenceStart` (UTC), or null.
     * @throws \Exception if the stored date cannot be parsed.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function minOccurrenceStart(int $elementId, int $siteId, string $fieldHandle): ?DateTimeImmutable
    {
        $min = (new Query())
            ->from(Install::OCCURRENCES_TABLE)
            ->where([
                'elementId' => $elementId,
                'siteId' => $siteId,
                'fieldHandle' => $fieldHandle,
            ])
            ->min('[[occurrenceStart]]');

        if ($min === null || $min === false) {
            return null;
        }

        return new DateTimeImmutable((string)$min, new DateTimeZone('UTC'));
    }

    // Private Methods
    // =========================================================================

    /**
     * Re-indexes a stream of elements, reporting progress.
     *
     * @param iterable<ElementInterface> $elements
     * @param ?callable $progress Optional `fn(int $done): void` progress callback.
     * @return int The number of elements re-indexed.
     * @throws \Throwable if a re-index fails.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _reindex(iterable $elements, ?callable $progress): int
    {
        $done = 0;

        foreach ($elements as $element) {
            $this->indexElement($element);
            $done++;

            if ($progress !== null) {
                $progress($done);
            }
        }

        return $done;
    }

    /**
     * Expands one field on an element and rewrites its index slice.
     *
     * @param ElementInterface $element
     * @param FieldInterface $field
     * @param ?int $limit A row cap, or null for the full window.
     * @return void
     * @throws \Exception if the recurrence cannot be expanded (see {@see RecurrenceModel::occurrenceRows()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _writeField(ElementInterface $element, FieldInterface $field, ?int $limit): void
    {
        $value = $element->getFieldValue($field->handle);
        $recurrence = $value instanceof TimeloopModel ? $this->_timeloop()->recurrenceFor($value) : null;

        if (!$value instanceof TimeloopModel || $recurrence === null) {
            $this->_clear((int)$element->id, (int)$element->siteId, $field->handle);

            return;
        }

        [$from, $to] = $this->_window($value);
        $rows = $recurrence->occurrenceRows($from, $to, $limit);

        $this->_writeRows((int)$element->id, (int)$element->siteId, $field->handle, $rows);
    }

    /**
     * Deletes an index slice and inserts the given occurrence rows in one transaction.
     *
     * Single-writer-per-slice is guarded by {@see Craft::$app->getMutex()}, not
     * a database constraint: two concurrent saves of the same element/site/field
     * (e.g. a save and a queued {@see ReindexOccurrences} job racing each other)
     * would otherwise interleave their delete+insert and could leave the slice
     * with duplicated or missing rows. The lock is best-effort: if it can't be
     * acquired within {@see LOCK_TIMEOUT} seconds, the write is skipped and a
     * warning is logged rather than blocking the request, and the nightly
     * `timeloop/occurrences/refresh` roll heals the slice on its next pass.
     *
     * @param int $elementId
     * @param int $siteId
     * @param string $fieldHandle
     * @param array<int, array{start: DateTimeImmutable, end: DateTimeImmutable}> $rows
     * @return void
     * @throws \yii\db\Exception if the delete or insert fails.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _writeRows(int $elementId, int $siteId, string $fieldHandle, array $rows): void
    {
        $mutex = Craft::$app->getMutex();
        $lockName = "timeloop:index:$elementId:$siteId:$fieldHandle";

        if (!$mutex->acquire($lockName, self::LOCK_TIMEOUT)) {
            Craft::warning(
                "Could not acquire the occurrence-index lock for \"$lockName\" within " . self::LOCK_TIMEOUT . 's; skipping this write. The nightly refresh will heal it.',
                __METHOD__,
            );

            return;
        }

        try {
            $db = Craft::$app->getDb();
            $now = Db::prepareDateForDb(new DateTimeImmutable('now', new DateTimeZone('UTC')));

            $insert = array_map(static fn(array $row): array => [
                $elementId,
                $siteId,
                $fieldHandle,
                Db::prepareDateForDb($row['start']),
                Db::prepareDateForDb($row['end']),
                $now,
                StringHelper::UUID(),
            ], $rows);

            $transaction = $db->beginTransaction();

            try {
                $db->createCommand()->delete(Install::OCCURRENCES_TABLE, [
                    'elementId' => $elementId,
                    'siteId' => $siteId,
                    'fieldHandle' => $fieldHandle,
                ])->execute();

                if ($insert !== []) {
                    $db->createCommand()->batchInsert(
                        Install::OCCURRENCES_TABLE,
                        ['elementId', 'siteId', 'fieldHandle', 'occurrenceStart', 'occurrenceEnd', 'dateCreated', 'uid'],
                        $insert,
                    )->execute();
                }

                $transaction->commit();
            } catch (Throwable $e) {
                $transaction->rollBack();

                throw $e;
            }
        } finally {
            $mutex->release($lockName);
        }
    }

    /**
     * Deletes an index slice.
     *
     * @param int $elementId
     * @param int $siteId
     * @param string $fieldHandle
     * @return void
     * @throws \yii\db\Exception if the delete fails.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _clear(int $elementId, int $siteId, string $fieldHandle): void
    {
        Craft::$app->getDb()->createCommand()->delete(Install::OCCURRENCES_TABLE, [
            'elementId' => $elementId,
            'siteId' => $siteId,
            'fieldHandle' => $fieldHandle,
        ])->execute();
    }

    /**
     * Reads the occurrence starts of an index slice within a range, in the given timezone.
     *
     * @param int $elementId
     * @param int $siteId
     * @param string $fieldHandle
     * @param DateTimeInterface $from The lower boundary (inclusive).
     * @param DateTimeInterface $to The upper boundary (inclusive).
     * @param DateTimeZone $timezone The value's timezone the starts are returned in.
     * @return DateTime[]
     * @throws \Exception if a stored date cannot be parsed.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _readStarts(int $elementId, int $siteId, string $fieldHandle, DateTimeInterface $from, DateTimeInterface $to, DateTimeZone $timezone): array
    {
        $starts = (new Query())
            ->select(['occurrenceStart'])
            ->from(Install::OCCURRENCES_TABLE)
            ->where([
                'elementId' => $elementId,
                'siteId' => $siteId,
                'fieldHandle' => $fieldHandle,
            ])
            ->andWhere(['>=', 'occurrenceStart', Db::prepareDateForDb($from)])
            ->andWhere(['<=', 'occurrenceStart', Db::prepareDateForDb($to)])
            ->orderBy(['occurrenceStart' => SORT_ASC])
            ->column();

        return array_map(
            static fn(string $utc): DateTime => (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone($timezone),
            $starts,
        );
    }

    /**
     * Returns the `[from, to]` expansion window for a value, in its timezone.
     *
     * `from` is `max(dtstart, now + indexPastHorizon)`: the series start,
     * floored so a long-running series is never expanded further back than the
     * configured past horizon (a value whose `dtstart` is more recent than the
     * floor is still indexed from its own true start). `to` is the horizon
     * measured from the later of now and the series start (so a far-future
     * series is still indexed). Both horizons are the plugin's `indexHorizon` /
     * `indexPastHorizon` settings, config-file overridable.
     *
     * @param TimeloopModel $value
     * @return DateTimeImmutable[] A two-element `[from, to]` list.
     * @throws \Exception if the stored `dtstart` or timezone cannot be parsed.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _window(TimeloopModel $value): array
    {
        $timezone = new DateTimeZone($value->timezone);
        $start = CarbonImmutable::instance(new DateTimeImmutable((string)$value->dtstart, $timezone));
        $now = CarbonImmutable::now($timezone);
        $floor = $now->modify($this->_pastHorizon());

        $from = $start->greaterThan($floor) ? $start : $floor;
        $to = ($start->greaterThan($now) ? $start : $now)->modify($this->_horizon());

        return [$from, $to];
    }

    /**
     * Returns the configured expansion horizon modifier, falling back when unavailable.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _horizon(): string
    {
        try {
            $plugin = Timeloop::getInstance();

            if ($plugin !== null) {
                $settings = $plugin->getSettings();

                if ($settings instanceof SettingsModel && $settings->indexHorizon !== '') {
                    return $settings->indexHorizon;
                }
            }
        } catch (Throwable) {
            // No booted plugin; fall through.
        }

        return self::FALLBACK_HORIZON;
    }

    /**
     * Returns the configured write-window floor modifier, falling back when unavailable.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _pastHorizon(): string
    {
        try {
            $plugin = Timeloop::getInstance();

            if ($plugin !== null) {
                $settings = $plugin->getSettings();

                if ($settings instanceof SettingsModel && $settings->indexPastHorizon !== '') {
                    return $settings->indexPastHorizon;
                }
            }
        } catch (Throwable) {
            // No booted plugin; fall through.
        }

        return self::FALLBACK_PAST_HORIZON;
    }

    /**
     * Returns whether an element is eligible for indexing (canonical, not a draft or revision).
     *
     * @param ElementInterface $element
     * @return bool
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _isIndexable(ElementInterface $element): bool
    {
        return $element->id !== null && $element->siteId !== null && !ElementHelper::isDraftOrRevision($element);
    }

    /**
     * Returns the Timeloop fields on an element's field layout.
     *
     * @param ElementInterface $element
     * @return TimeloopField[]
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _timeloopFields(ElementInterface $element): array
    {
        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return [];
        }

        return array_values(array_filter(
            $layout->getCustomFields(),
            static fn(FieldInterface $field): bool => $field instanceof TimeloopField,
        ));
    }

    /**
     * Streams every canonical element that carries a Timeloop field value.
     *
     * Walks `elements_sites` for content that references a Timeloop layout element
     * (the same approach as the v2 content migration), skipping drafts, revisions
     * and trashed elements, and yields each loaded element in its site.
     *
     * @return iterable<ElementInterface>
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _allIndexableElements(): iterable
    {
        $uids = $this->_timeloopLayoutElementUids();

        if ($uids === []) {
            return;
        }

        $query = (new Query())
            ->select(['es.elementId', 'es.siteId', 'es.content'])
            ->from(['es' => CraftTable::ELEMENTS_SITES])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[es.elementId]]')
            ->where(['e.dateDeleted' => null, 'e.draftId' => null, 'e.revisionId' => null])
            ->andWhere(['not', ['es.content' => null]])
            ->orderBy(['es.elementId' => SORT_ASC]);

        foreach (Db::each($query) as $row) {
            $content = is_string($row['content']) ? json_decode($row['content'], true) : $row['content'];

            if (!is_array($content) || array_intersect_key($content, $uids) === []) {
                continue;
            }

            $element = Craft::$app->getElements()->getElementById((int)$row['elementId'], null, (int)$row['siteId']);

            if ($element !== null) {
                yield $element;
            }
        }
    }

    /**
     * Streams every element that currently holds index rows, loaded in its site.
     *
     * @return iterable<ElementInterface>
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _indexedElements(): iterable
    {
        $query = (new Query())
            ->select(['elementId', 'siteId'])
            ->distinct()
            ->from(Install::OCCURRENCES_TABLE)
            ->orderBy(['elementId' => SORT_ASC]);

        foreach (Db::each($query) as $row) {
            $element = Craft::$app->getElements()->getElementById((int)$row['elementId'], null, (int)$row['siteId']);

            if ($element !== null) {
                yield $element;
            }
        }
    }

    /**
     * Returns every field-layout-element UID that maps to a Timeloop field.
     *
     * @return array<string, string> A `uid => uid` map for cheap `array_intersect_key`.
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

        return $uids;
    }

    /**
     * Returns the Timeloop service, preferring the plugin's registered singleton.
     *
     * @return TimeloopService
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _timeloop(): TimeloopService
    {
        try {
            $plugin = Timeloop::getInstance();

            if ($plugin !== null && $plugin->has('timeloop')) {
                return $plugin->getTimeloop();
            }
        } catch (Throwable) {
            // No booted plugin; fall through.
        }

        return new TimeloopService();
    }
}
