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
use yii\base\InvalidConfigException;

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
 * multiple *different* params on one query never collide (e.g. `activeTimeloop('a')`
 * combined with `timeloopNext('b')`). Occurrence columns are stored UTC, so
 * every comparison is normalized through {@see Db::parseDateParam()} and every
 * handle through {@see Db::parseParam()}.
 *
 * Each individual param, however, holds state for a single field handle:
 * calling the same param method twice with the same handle is a harmless
 * no-op/overwrite, but calling it twice with two *different* handles throws
 * {@see \yii\base\InvalidConfigException} ({@see _guardSingleHandle()}), since
 * only the last-set handle would actually be applied, silently dropping the
 * first. Filtering on two different Timeloop fields in one query requires two
 * separate params (e.g. `activeTimeloop($handleA)` and `timeloopBetween($handleB, ...)`).
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
     * Unlike `timeloopBetween()`, this is unaffected by the occurrence index's
     * write-window floor (`indexPastHorizon`): "now" always falls inside the
     * indexed window, since the window is defined relative to now on every
     * write.
     *
     * Only one field handle can be filtered per query: calling this twice with
     * the same handle is a harmless no-op/overwrite, but calling it twice with
     * two *different* handles throws, since only the last-set handle would
     * actually be applied (silently dropping the first filter) rather than
     * combining both.
     *
     * @param string $fieldHandle The Timeloop field handle.
     * @return ElementQuery
     * @throws InvalidConfigException if already called with a different field handle.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function activeTimeloop(string $fieldHandle): ElementQuery
    {
        $this->_guardSingleHandle($this->_activeHandle, $fieldHandle, 'activeTimeloop()');

        $this->_activeHandle = $fieldHandle;

        return $this->_query();
    }

    /**
     * Filters to elements with an occurrence starting within a range (inclusive).
     *
     * Index-backed, so it only ever sees the window the occurrence index
     * actually covers: `now + indexPastHorizon` back to `now + indexHorizon`
     * (default one year back to two years ahead, both config-file overridable
     * via {@see \craftpulse\timeloop\models\SettingsModel}). A range reaching
     * outside that window simply matches nothing there, since this param
     * queries the index directly rather than falling back to live expansion
     * the way `recurringDates()` does; a historical query beyond the floor
     * should use `recurringDates()` or the value-level API instead
     * ({@see \craftpulse\timeloop\models\TimeloopModel}).
     *
     * Only one field handle can be filtered per query: calling this twice with
     * the same handle is a harmless no-op/overwrite, but calling it twice with
     * two *different* handles throws, since only the last-set handle would
     * actually be applied (silently dropping the first filter) rather than
     * combining both.
     *
     * @param string $fieldHandle The Timeloop field handle.
     * @param mixed $from The lower boundary (a date string or `\DateTime`).
     * @param mixed $to The upper boundary (a date string or `\DateTime`).
     * @return ElementQuery
     * @throws InvalidConfigException if already called with a different field handle.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function timeloopBetween(string $fieldHandle, mixed $from, mixed $to): ElementQuery
    {
        $this->_guardSingleHandle($this->_between['handle'] ?? null, $fieldHandle, 'timeloopBetween()');

        $this->_between = ['handle' => $fieldHandle, 'from' => $from, 'to' => $to];

        return $this->_query();
    }

    /**
     * Registers the `timeloopNext` order column (earliest upcoming occurrence start).
     *
     * Only one field handle can be ordered per query: calling this twice with
     * the same handle is a harmless no-op/overwrite, but calling it twice with
     * two *different* handles throws, since only the last-set handle would
     * actually be applied (silently dropping the first order column) rather
     * than combining both.
     *
     * @param string $fieldHandle The Timeloop field handle.
     * @return ElementQuery
     * @throws InvalidConfigException if already called with a different field handle.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function timeloopNext(string $fieldHandle): ElementQuery
    {
        $this->_guardSingleHandle($this->_nextHandle, $fieldHandle, 'timeloopNext()');

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
     * Guards a param against being set for a second, different field handle.
     *
     * @param ?string $current The handle already registered for this param, if any.
     * @param string $requested The handle being requested.
     * @param string $method The calling method's name, for the exception message.
     * @return void
     * @throws InvalidConfigException if `$current` is set and differs from `$requested`.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _guardSingleHandle(?string $current, string $requested, string $method): void
    {
        if ($current === null || $current === $requested) {
            return;
        }

        throw new InvalidConfigException(sprintf(
            '%s was already called with field handle "%s" on this query; only one Timeloop field can be used per param, and "%s" was requested next.',
            $method,
            $current,
            $requested,
        ));
    }

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
