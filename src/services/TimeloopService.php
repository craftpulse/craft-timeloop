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

use craft\base\Component;
use craft\base\Model;
use craftpulse\timeloop\models\RecurrenceModel;
use craftpulse\timeloop\models\TimeloopModel;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Timeloop recurrence service.
 *
 * Expands a Timeloop field value into its recurrence dates. As of 5.1.0 the
 * expansion is delegated to the {@see RecurrenceModel} engine; the legacy
 * `getLoop()`/`getReminder()`/`getLoopBetweenDates()` surface is preserved and
 * produces output identical to 5.0.0.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class TimeloopService extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var int The maximum number of dates returned when no limit is given.
     */
    public const MAX_ARRAY_ENTRIES = 100;

    /**
     * @var string The default expansion horizon for infinite rules and future-date queries.
     */
    private const DEFAULT_HORIZON = '+20 years';

    // Public Methods
    // =========================================================================

    /**
     * Returns the loop period configuration of the given field value.
     *
     * @param TimeloopModel $data
     * @return ?Model
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function showPeriod(TimeloopModel $data): ?Model
    {
        return $data->getPeriod();
    }

    /**
     * Returns the recurrence dates for the given field value.
     *
     * Mirrors the 5.0.0 contract: `futureDates` returns occurrences strictly
     * after now within a 20-year horizon; a `0` limit falls back to
     * [[MAX_ARRAY_ENTRIES]]. Returns `null` when the value carries no rule.
     *
     * @param TimeloopModel|array $data
     * @param int $limit Maximum number of dates to return, `0` falls back to [[MAX_ARRAY_ENTRIES]].
     * @param bool $futureDates Whether only dates after now should be returned.
     * @return ?DateTime[]
     * @throws \Exception if the recurrence cannot be expanded (see {@see RecurrenceModel}).
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getLoop(TimeloopModel|array $data, int $limit = 0, bool $futureDates = true): ?array
    {
        if (!$data instanceof TimeloopModel) {
            return null;
        }

        $recurrence = $data->getRecurrence();

        if ($recurrence === null) {
            return null;
        }

        if ($limit === 0) {
            $limit = self::MAX_ARRAY_ENTRIES;
        }

        // 5.0.0 expanded from the start date up to (loopEndDate ?? now + 20 years),
        // breaking once the limit was hit; `futureDates` then dropped anything at
        // or before now. The rule already carries the end date as its UNTIL, so
        // the horizon only needs to cap otherwise-infinite rules, and the lower
        // bound switches between the start date and now.
        $now = new DateTimeImmutable('now', new DateTimeZone($data->timezone));
        $from = $futureDates ? $now : new DateTimeImmutable((string)$data->dtstart, new DateTimeZone($data->timezone));
        $occurrences = $recurrence->occurrencesBetween($from, $now->modify(self::DEFAULT_HORIZON), $limit);

        return array_map(static fn(DateTimeImmutable $date): DateTime => DateTime::createFromInterface($date), $occurrences);
    }

    /**
     * Returns the dates of the given set that fall between two dates (inclusive).
     *
     * @param array $dates
     * @param DateTime $start
     * @param DateTime $end
     * @return array
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getLoopBetweenDates(array $dates, DateTime $start, DateTime $end): array
    {
        $loopDates = [];

        foreach ($dates as $date) {
            if ($date >= $start && $date <= $end) {
                $loopDates[] = $date;
            }
        }

        return $loopDates;
    }

    /**
     * Returns the reminder date for the first upcoming occurrence.
     *
     * @param TimeloopModel $data
     * @return ?DateTime
     * @throws \Exception if the recurrence cannot be expanded (see {@see getLoop()}).
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getReminder(TimeloopModel $data): ?DateTime
    {
        if (!$data->loopReminderPeriod) {
            return null;
        }

        $dates = $this->getLoop($data, 1);

        if (empty($dates)) {
            return null;
        }

        return (clone $dates[0])->modify(sprintf('-%d %s', $data->loopReminderValue ?? 0, $data->loopReminderPeriod));
    }

    // Recurrence Engine Adapters
    // =========================================================================

    /**
     * Returns the occurrences of a recurrence, in its stored timezone.
     *
     * @param RecurrenceModel $model
     * @param ?int $limit Maximum number of occurrences (null or `0` means everything for a finite rule).
     * @return DateTimeImmutable[]
     * @throws \InvalidArgumentException if the rule cannot be parsed.
     * @throws \Exception if the model's `dtstart` or `timezone` cannot be parsed into a date-time, or
     * an exclusion/extra date cannot be parsed (see {@see RecurrenceModel::occurrences()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function occurrences(RecurrenceModel $model, ?int $limit = null): array
    {
        return $model->occurrences($limit);
    }

    /**
     * Returns the first occurrence of a recurrence, in its stored timezone.
     *
     * @param RecurrenceModel $model
     * @return ?DateTimeImmutable
     * @throws \InvalidArgumentException if the rule cannot be parsed.
     * @throws \Exception if the model's `dtstart` or `timezone` cannot be parsed into a date-time, or
     * an exclusion/extra date cannot be parsed (see {@see RecurrenceModel::firstOccurrence()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function firstOccurrence(RecurrenceModel $model): ?DateTimeImmutable
    {
        return $model->firstOccurrence();
    }

    /**
     * Returns the occurrences between two dates (inclusive of both boundaries).
     *
     * @param RecurrenceModel $model
     * @param DateTimeInterface $from The lower boundary (inclusive).
     * @param DateTimeInterface $to The upper boundary (inclusive).
     * @param ?int $limit Maximum number of occurrences (null or `0` means everything within the range).
     * @return DateTimeImmutable[]
     * @throws \InvalidArgumentException if the rule cannot be parsed.
     * @throws \Exception if the model's `dtstart` or `timezone` cannot be parsed into a date-time, or
     * an exclusion/extra date cannot be parsed (see {@see RecurrenceModel::occurrencesBetween()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function occurrencesBetween(RecurrenceModel $model, DateTimeInterface $from, DateTimeInterface $to, ?int $limit = null): array
    {
        return $model->occurrencesBetween($from, $to, $limit);
    }

    /**
     * Returns the first occurrence strictly after the given date.
     *
     * @param RecurrenceModel $model
     * @param ?DateTimeInterface $after The reference date, defaulting to now in the stored timezone.
     * @return ?DateTimeImmutable
     * @throws \InvalidArgumentException if the rule cannot be parsed.
     * @throws \Exception if the model's `dtstart` or `timezone` cannot be parsed into a date-time, if
     * `$after` defaults to `now` and cannot be constructed, or an exclusion/extra date cannot be
     * parsed (see {@see RecurrenceModel::nextOccurrence()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function nextOccurrence(RecurrenceModel $model, ?DateTimeInterface $after = null): ?DateTimeImmutable
    {
        return $model->nextOccurrence($after);
    }

    /**
     * Returns whether an occurrence starts exactly at the given date-time.
     *
     * @param RecurrenceModel $model
     * @param DateTimeInterface $dateTime
     * @return bool
     * @throws \InvalidArgumentException if the rule cannot be parsed.
     * @throws \Exception if the model's `dtstart` or `timezone` cannot be parsed into a date-time, or
     * an exclusion/extra date cannot be parsed (see {@see RecurrenceModel::occursAt()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function occursAt(RecurrenceModel $model, DateTimeInterface $dateTime): bool
    {
        return $model->occursAt($dateTime);
    }

    /**
     * Returns whether an occurrence is in progress at the given date-time.
     *
     * @param RecurrenceModel $model
     * @param DateTimeInterface $dateTime
     * @return bool
     * @throws \InvalidArgumentException if the rule cannot be parsed.
     * @throws \Exception if the model's `dtstart` or `timezone` cannot be parsed into a date-time, or
     * an exclusion/extra date cannot be parsed (see {@see RecurrenceModel::activeAt()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function activeAt(RecurrenceModel $model, DateTimeInterface $dateTime): bool
    {
        return $model->activeAt($dateTime);
    }
}
