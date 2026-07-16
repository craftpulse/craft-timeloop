<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\models;

use craft\base\Model;
use craftpulse\timeloop\Timeloop;
use DateTime;
use DateTimeZone;

/**
 * Timeloop field value model.
 *
 * As of 5.1.0 the field value is stored in the v2 shape (a local `dtstart`, an
 * IANA `timezone`, an `rrule`, static `exdates`/`rdates`, a `holidays` object,
 * an `endTime` and a `reminder`) and every recurrence expansion is delegated to
 * the {@see RecurrenceModel} engine.
 *
 * The full pre-5.1 public surface is preserved as a backwards-compatibility
 * shim. The legacy `loopStartDate`/`loopEndDate`/`loopStartTime`/`loopEndTime`/
 * `loopPeriod`/`loopReminderValue`/`loopReminderPeriod` values remain real
 * public properties (so `entry.field.loopStartDate` keeps returning a
 * `\DateTime` and the control-panel form keeps round-tripping) but are now
 * *derived* from the v2 data in [[init()]]; the v2 properties are the source of
 * truth. `getDates()`/`getUpcoming()`/`getPeriod()`/`getTimeString()`/
 * `getReminder()` and the `getLoop*()` getters keep their pre-5.1 signatures.
 * {@see PeriodModel}/{@see TimeStringModel} are parsed back out of the `rrule`
 * by {@see ValueNormalizer}.
 *
 * The derived `loopStart*`/`loopEnd*` `\DateTime` values are rebuilt on the
 * `dtstart` date, so their time-of-day (and therefore `getLoopStartTime()` /
 * `getLoopEndTime()`) matches 5.0.0 exactly; the arbitrary reference *date* the
 * old time picker stored is not reproduced, which only affects the seldom-used
 * `getLoopStart()`/`getLoopEnd()` `\DateTime` date component, never their time.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class TimeloopModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var int The storage format version.
     */
    public int $version = ValueNormalizer::VERSION;

    /**
     * @var ?string The local start date (and optional time), e.g. `2026-09-07T19:00:00`.
     */
    public ?string $dtstart = null;

    /**
     * @var string The IANA timezone the recurrence is stored and expanded in.
     */
    public string $timezone = 'UTC';

    /**
     * @var ?string The RRULE string without a `DTSTART`.
     */
    public ?string $rrule = null;

    /**
     * @var string[] Static exclusion dates removed from the set.
     */
    public array $exdates = [];

    /**
     * @var string[] Extra one-off dates added to the set.
     */
    public array $rdates = [];

    /**
     * @var array{enabled: bool, country: ?string, region: ?string} The holiday exclusion configuration.
     */
    public array $holidays = ['enabled' => false, 'country' => null, 'region' => null];

    /**
     * @var ?string The wall-clock end time each occurrence runs until, formatted `H:i`. Null means all-day.
     */
    public ?string $endTime = null;

    /**
     * @var array{value: int, period: ?string} The reminder configuration.
     */
    public array $reminder = ['value' => 0, 'period' => null];

    /**
     * @var ?DateTime The date the loop starts (derived; backwards-compatibility).
     */
    public ?DateTime $loopStartDate = null;

    /**
     * @var ?DateTime The date the loop ends (derived; backwards-compatibility).
     */
    public ?DateTime $loopEndDate = null;

    /**
     * @var ?DateTime The time of day each occurrence starts (derived; backwards-compatibility).
     */
    public ?DateTime $loopStartTime = null;

    /**
     * @var ?DateTime The time of day each occurrence ends (derived; backwards-compatibility).
     */
    public ?DateTime $loopEndTime = null;

    /**
     * @var ?string The reminder period unit, e.g. `days` (derived; backwards-compatibility).
     */
    public ?string $loopReminderPeriod = null;

    /**
     * @var ?int The reminder period value (derived; backwards-compatibility).
     */
    public ?int $loopReminderValue = null;

    /**
     * @var ?array The loop period configuration (derived; backwards-compatibility).
     */
    public ?array $loopPeriod = null;

    // Private Properties
    // =========================================================================

    /**
     * @var ?RecurrenceModel Memoized recurrence engine.
     */
    private ?RecurrenceModel $_recurrence = null;

    /**
     * @var ?array Memoized upcoming occurrences.
     */
    private ?array $_upcomingDates = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Derives the legacy backwards-compatibility properties from the v2 data.
     *
     * @throws \Exception if the stored timezone, `dtstart` or rule `UNTIL` cannot be parsed.
     */
    public function init(): void
    {
        parent::init();

        $this->loopPeriod = ValueNormalizer::rruleToLoopPeriod($this->rrule);
        $this->loopReminderPeriod = $this->reminder['period'] ?? null;
        $this->loopReminderValue = (int)$this->reminder['value'];

        if ($this->dtstart === null) {
            return;
        }

        $timezone = new DateTimeZone($this->timezone);
        $this->loopStartDate = new DateTime($this->dtstart, $timezone);
        $this->loopStartTime = new DateTime($this->dtstart, $timezone);

        if ($this->endTime !== null) {
            [$hour, $minute] = array_map('intval', explode(':', $this->endTime));
            $this->loopEndTime = (new DateTime($this->dtstart, $timezone))->setTime($hour, $minute, 0);
        }

        $until = $this->rrule !== null ? ValueNormalizer::parseRrule($this->rrule)['until'] : null;

        if ($until !== null) {
            $this->loopEndDate = (new DateTime($until, new DateTimeZone('UTC')))->setTimezone($timezone);
        }
    }

    /**
     * Returns the recurrence engine for this value, or null when there is no rule.
     *
     * @return ?RecurrenceModel
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function getRecurrence(): ?RecurrenceModel
    {
        if ($this->dtstart === null || $this->rrule === null || $this->rrule === '') {
            return null;
        }

        return $this->_recurrence ??= new RecurrenceModel([
            'dtstart' => $this->dtstart,
            'timezone' => $this->timezone,
            'rrule' => $this->rrule,
            'exdates' => $this->exdates,
            'rdates' => $this->rdates,
            'endTime' => $this->endTime,
        ]);
    }

    /**
     * Returns the canonical v2 value for storage.
     *
     * @return array
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function toV2Array(): array
    {
        return [
            'version' => ValueNormalizer::VERSION,
            'dtstart' => $this->dtstart,
            'timezone' => $this->timezone,
            'rrule' => $this->rrule,
            'exdates' => array_values($this->exdates),
            'rdates' => array_values($this->rdates),
            'holidays' => [
                'enabled' => (bool)($this->holidays['enabled'] ?? false),
                'country' => $this->holidays['country'] ?? null,
                'region' => $this->holidays['region'] ?? null,
            ],
            'endTime' => $this->endTime,
            'reminder' => [
                'value' => (int)($this->reminder['value'] ?? 0),
                'period' => ($this->reminder['period'] ?? null) ?: null,
            ],
        ];
    }

    /**
     * Returns whether the value carries no recurrence.
     *
     * @return bool
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function isEmpty(): bool
    {
        return $this->dtstart === null;
    }

    // Backwards-compatibility shim
    // =========================================================================

    /**
     * Returns the loop start time formatted as `H:i`, or null.
     *
     * @return ?string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getLoopStartTime(): ?string
    {
        return $this->loopStartTime?->format('H:i');
    }

    /**
     * Returns the loop end time formatted as `H:i`, or null.
     *
     * @return ?string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getLoopEndTime(): ?string
    {
        return $this->endTime;
    }

    /**
     * Returns the loop start time as a DateTime object.
     *
     * @return ?DateTime
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getLoopStart(): ?DateTime
    {
        return $this->loopStartTime;
    }

    /**
     * Returns the loop end time as a DateTime object.
     *
     * @return ?DateTime
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getLoopEnd(): ?DateTime
    {
        return $this->loopEndTime;
    }

    /**
     * Returns the loop period configuration as a model.
     *
     * @return ?PeriodModel
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getPeriod(): ?PeriodModel
    {
        if ($this->loopPeriod === null) {
            return null;
        }

        return new PeriodModel($this->loopPeriod);
    }

    /**
     * Returns the monthly timestring configuration as a model.
     *
     * @return ?TimeStringModel
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getTimeString(): ?TimeStringModel
    {
        if ($this->loopPeriod === null || empty($this->loopPeriod['timestring'])) {
            return null;
        }

        return new TimeStringModel($this->loopPeriod['timestring']);
    }

    /**
     * Returns the reminder date for the first upcoming occurrence.
     *
     * @return ?DateTime
     * @throws \Exception if the recurrence cannot be expanded (see {@see TimeloopService::getReminder()}).
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getReminder(): ?DateTime
    {
        return Timeloop::$plugin->timeloop->getReminder($this);
    }

    /**
     * Returns the computed recurrence dates.
     *
     * @param int $limit
     * @param bool $futureDates
     * @return ?array
     * @throws \Exception if the recurrence cannot be expanded (see {@see TimeloopService::getLoop()}).
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getDates(int $limit = 0, bool $futureDates = true): ?array
    {
        return Timeloop::$plugin->timeloop->getLoop($this, $limit, $futureDates);
    }

    /**
     * Returns the first upcoming occurrence.
     *
     * @return ?DateTime
     * @throws \Exception if the recurrence cannot be expanded (see {@see TimeloopService::getLoop()}).
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getUpcoming(): ?DateTime
    {
        return $this->_getUpcomingDates()[0] ?? null;
    }

    /**
     * Returns the second upcoming occurrence.
     *
     * @return ?DateTime
     * @throws \Exception if the recurrence cannot be expanded (see {@see TimeloopService::getLoop()}).
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getNextUpcoming(): ?DateTime
    {
        return $this->_getUpcomingDates()[1] ?? null;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['dtstart', 'timezone', 'rrule', 'endTime'], 'safe'];
        $rules[] = [['exdates', 'rdates', 'holidays', 'reminder', 'loopPeriod'], 'safe'];
        $rules[] = [['loopStartDate', 'loopEndDate', 'loopStartTime', 'loopEndTime'], 'safe'];
        $rules[] = [['version', 'loopReminderValue'], 'integer'];

        return $rules;
    }

    // Private Methods
    // =========================================================================

    /**
     * Memoizes and returns the next two upcoming occurrences.
     *
     * @return array
     * @throws \Exception if the recurrence cannot be expanded (see {@see TimeloopService::getLoop()}).
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _getUpcomingDates(): array
    {
        return $this->_upcomingDates ??= Timeloop::$plugin->timeloop->getLoop($this, 2) ?? [];
    }
}
