<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\behaviors;

use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craftpulse\timeloop\migrations\Install;
use yii\base\Behavior;
use yii\base\Event;

/**
 * Occurrence-index element-query params.
 *
 * Attached to every {@see ElementQuery} (entries and all other element types),
 * this behavior adds three index-backed params that answer date-range questions
 * without expanding any recurrence at query time:
 *
 * - `activeTimeloop($fieldHandle)` — elements with an occurrence in progress
 *   right now (`occurrenceStart <= now < occurrenceEnd`).
 * - `timeloopBetween($fieldHandle, $from, $to)` — elements with an occurrence
 *   whose start falls in `[from, to]` (both inclusive).
 * - `timeloopNext($fieldHandle)` — registers a `timeloopNext` order column (the
 *   element's earliest upcoming occurrence start), so a board can be sorted with
 *   `->orderBy('timeloopNext')`. Elements with no upcoming occurrence carry a
 *   NULL, which MySQL sorts first ascending; filter them with `activeTimeloop()`
 *   or `timeloopBetween()` when that matters.
 *
 * All three are index-backed and compose: each adds a correlated `EXISTS`
 * subquery (or, for ordering, a correlated `MIN` select) against
 * `{{%timeloop_occurrences}}`, scoped to the queried element and its site, so
 * multiple params on one query never collide. Occurrence columns are stored UTC,
 * so every comparison is normalized through {@see Db::parseDateParam()} and every
 * handle through {@see Db::parseParam()}.
 *
 * The `elementId` an occurrence row is keyed on is the element that directly
 * carries the Timeloop field, so these params match a field sitting directly on
 * the queried element (the dance-school board queries top-level class entries).
 *
 * @author CraftPulse
 * @since 5.1.0
 */
class TimeloopQueryBehavior extends Behavior
{
    // Private Properties
    // =========================================================================

    /**
     * @var ?string The field handle for the `activeTimeloop()` filter.
     */
    private ?string $_activeHandle = null;

    /**
     * @var ?array{handle: string, from: mixed, to: mixed} The `timeloopBetween()` filter state.
     */
    private ?array $_between = null;

    /**
     * @var ?string The field handle for the `timeloopNext` order column.
     */
    private ?string $_nextHandle = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function events(): array
    {
        return [
            ElementQuery::EVENT_BEFORE_PREPARE => 'beforePrepare',
        ];
    }

    /**
     * Filters to elements with an occurrence in progress right now.
     *
     * @param string $fieldHandle The Timeloop field handle.
     * @return ElementQuery
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function activeTimeloop(string $fieldHandle): ElementQuery
    {
        $this->_activeHandle = $fieldHandle;

        return $this->_query();
    }

    /**
     * Filters to elements with an occurrence starting within a range (inclusive).
     *
     * @param string $fieldHandle The Timeloop field handle.
     * @param mixed $from The lower boundary (a date string or `\DateTime`).
     * @param mixed $to The upper boundary (a date string or `\DateTime`).
     * @return ElementQuery
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function timeloopBetween(string $fieldHandle, mixed $from, mixed $to): ElementQuery
    {
        $this->_between = ['handle' => $fieldHandle, 'from' => $from, 'to' => $to];

        return $this->_query();
    }

    /**
     * Registers the `timeloopNext` order column (earliest upcoming occurrence start).
     *
     * @param string $fieldHandle The Timeloop field handle.
     * @return ElementQuery
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function timeloopNext(string $fieldHandle): ElementQuery
    {
        $this->_nextHandle = $fieldHandle;

        return $this->_query();
    }

    /**
     * Applies the registered Timeloop params to the query being prepared.
     *
     * @param Event $event
     * @return void
     * @throws \Exception if a boundary value cannot be parsed into a date-time.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function beforePrepare(Event $event): void
    {
        $query = $this->owner;

        if (!$query instanceof ElementQuery) {
            return;
        }

        $now = DateTimeHelper::currentUTCDateTime();

        if ($this->_activeHandle !== null) {
            $query->subQuery->andWhere(['exists', (new Query())
                ->from(['tl_active' => Install::OCCURRENCES_TABLE])
                ->where('[[tl_active.elementId]] = [[elements.id]]')
                ->andWhere('[[tl_active.siteId]] = [[elements_sites.siteId]]')
                ->andWhere(Db::parseParam('tl_active.fieldHandle', $this->_activeHandle))
                ->andWhere(Db::parseDateParam('tl_active.occurrenceStart', $now, '<='))
                ->andWhere(Db::parseDateParam('tl_active.occurrenceEnd', $now, '>')),
            ]);
        }

        if ($this->_between !== null) {
            $query->subQuery->andWhere(['exists', (new Query())
                ->from(['tl_between' => Install::OCCURRENCES_TABLE])
                ->where('[[tl_between.elementId]] = [[elements.id]]')
                ->andWhere('[[tl_between.siteId]] = [[elements_sites.siteId]]')
                ->andWhere(Db::parseParam('tl_between.fieldHandle', $this->_between['handle']))
                ->andWhere(Db::parseDateParam('tl_between.occurrenceStart', $this->_between['from'], '>='))
                ->andWhere(Db::parseDateParam('tl_between.occurrenceStart', $this->_between['to'], '<=')),
            ]);
        }

        if ($this->_nextHandle !== null) {
            $query->subQuery->addSelect(['timeloopNext' => (new Query())
                ->select('MIN([[tl_next.occurrenceStart]])')
                ->from(['tl_next' => Install::OCCURRENCES_TABLE])
                ->where('[[tl_next.elementId]] = [[elements.id]]')
                ->andWhere('[[tl_next.siteId]] = [[elements_sites.siteId]]')
                ->andWhere(Db::parseParam('tl_next.fieldHandle', $this->_nextHandle))
                ->andWhere(Db::parseDateParam('tl_next.occurrenceStart', $now, '>=')),
            ]);
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the owner element query.
     *
     * @return ElementQuery
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _query(): ElementQuery
    {
        assert($this->owner instanceof ElementQuery);

        return $this->owner;
    }
}
