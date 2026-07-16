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
use craft\helpers\DateTimeHelper;
use craftpulse\timeloop\models\PeriodModel;
use craftpulse\timeloop\models\RecurrenceModel;
use craftpulse\timeloop\models\TimeloopModel;
use craftpulse\timeloop\models\TimeStringModel;
use DateInterval;
use DatePeriod;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Timeloop recurrence service.
 *
 * Expands a Timeloop field value into its recurrence dates.
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
     * @param TimeloopModel|array $data
     * @param int $limit Maximum number of dates to return, `0` falls back to [[MAX_ARRAY_ENTRIES]].
     * @param bool $futureDates Whether only dates after now should be returned.
     * @return ?array
     * @throws \Exception
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getLoop(TimeloopModel|array $data, int $limit = 0, bool $futureDates = true): ?array
    {
        if (!$data instanceof TimeloopModel || $data->loopStartDate === null) {
            return null;
        }

        $period = $data->getPeriod();

        if ($period === null) {
            return null;
        }

        // Use today + 20 years as the default end date to get way ahead in the future
        $end = $data->loopEndDate ?? (new DateTime())->modify('+20 years');
        $timestring = new TimeStringModel($period->timestring);

        // If no limit is set, use the default so we don't end up with high number arrays
        if ($limit === 0) {
            $limit = self::MAX_ARRAY_ENTRIES;
        }

        return $this->_fetchDates($data->loopStartDate, $end, $period, $timestring, $limit, $futureDates);
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
     * @throws \Exception
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

        $reminder = (clone $dates[0])->modify(sprintf('-%d %s', $data->loopReminderValue ?? 0, $data->loopReminderPeriod));

        return $reminder ?: null;
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
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function occurrences(RecurrenceModel $model, ?int $limit = null): array
    {
        return $model->occurrences($limit);
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
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function activeAt(RecurrenceModel $model, DateTimeInterface $dateTime): bool
    {
        return $model->activeAt($dateTime);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns an array with all the dates between a start and end point,
     * based on the period entered.
     *
     * @param DateTime $start
     * @param DateTime $end
     * @param PeriodModel $period
     * @param TimeStringModel $timestring
     * @param int $limit
     * @param bool $futureDates
     * @return array
     * @throws \Exception
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private function _fetchDates(DateTime $start, DateTime $end, PeriodModel $period, TimeStringModel $timestring, int $limit = 0, bool $futureDates = true): array
    {
        $interval = $this->_calculateInterval($period);
        $today = new DateTime();

        $dateInterval = new DateInterval($interval->interval);
        $datePeriod = new DatePeriod($start, $dateInterval, $end);

        $arrDates = [];
        $counter = 0;

        foreach ($datePeriod as $date) {
            $dateToParse = $interval->frequency === 'monthly' ? $start : $date;
            $loopDates = $this->_parseDate($interval->frequency, $dateToParse, $end, $counter, $period, $timestring);

            if (!is_array($loopDates)) {
                $loopDates = [$loopDates];
            }

            foreach ($loopDates as $loopDate) {
                // Weekly expansion can produce dates earlier in the start date's week
                if ($loopDate < $start) {
                    continue;
                }

                if ($futureDates && $loopDate <= $today) {
                    continue;
                }

                $arrDates[] = $loopDate;
            }

            if ($limit > 0 && count($arrDates) >= $limit) {
                break;
            }

            $counter++;
        }

        if ($limit > 0) {
            $arrDates = array_slice($arrDates, 0, $limit);
        }

        return $arrDates;
    }

    /**
     * Returns the DateInterval spec and frequency name for the given period.
     *
     * @param PeriodModel $period
     * @return object
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private function _calculateInterval(PeriodModel $period): object
    {
        $cycle = max(1, $period->cycle);

        return match ($period->frequency) {
            'P1D' => (object)[
                'interval' => "P{$cycle}D",
                'frequency' => 'daily',
            ],
            'P1W' => (object)[
                'interval' => "P{$cycle}W",
                'frequency' => 'weekly',
            ],
            'P1M' => (object)[
                'interval' => "P{$cycle}M",
                'frequency' => 'monthly',
            ],
            default => (object)[
                'interval' => "P{$cycle}Y",
                'frequency' => 'yearly',
            ],
        };
    }

    /**
     * Returns the $date with the month corrected for a monthly loop.
     *
     * Correctly calculates end of months when we shift to a shorter or longer month:
     * shifting from the 28th Feb +1 month is 31st March,
     * shifting from the 28th Feb -1 month is 31st Jan,
     * shifting from the 29, 30, 31 Jan +1 month is 28th (or 29th) Feb.
     *
     * @param DateTime $date
     * @param int $months
     * @param int $cycle
     * @return DateTime
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private function _monthCorrection(DateTime $date, int $months, int $cycle): DateTime
    {
        $frequency = $months * $cycle;

        // Making 2 clones of our dates to be able to do calculations
        $date1 = clone($date);
        $date2 = clone($date);

        $addedMonths = clone($date1->modify($frequency . ' Month'));

        if ($date2 != $date1->modify($frequency * -1 . ' Month')) {
            return $addedMonths->modify('last day of last month');
        }

        if ($date == $date2->modify('last day of this month')) {
            return $addedMonths->modify('last day of this month');
        }

        return $addedMonths;
    }

    /**
     * Returns the date(s) to add to the result for the given frequency.
     *
     * @param string $frequency
     * @param DateTime $date
     * @param DateTime $end
     * @param int $counter The loop counter
     * @param PeriodModel $period
     * @param TimeStringModel $timestring
     * @return DateTime|array
     * @throws \Exception
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private function _parseDate(string $frequency, DateTime $date, DateTime $end, int $counter, PeriodModel $period, TimeStringModel $timestring): DateTime|array
    {
        switch ($frequency) {
            case 'weekly':
                if (count($period->days) === 0) {
                    $loopDate = $date;
                    break;
                }

                $weekDates = [];
                $hours = (int)$date->format('H');
                $minutes = (int)$date->format('i');

                foreach ($period->days as $day) {
                    $weekDay = clone($date)->modify(strtolower($day) . ' this week')->setTime($hours, $minutes);

                    if ($weekDay <= $end) {
                        $weekDates[] = DateTimeHelper::toDateTime($weekDay);
                    }
                }

                $loopDate = $weekDates;
                break;
            case 'monthly':
                $monthlyDate = $this->_monthCorrection($date, $counter, $period->cycle);
                $hours = (int)$date->format('H');
                $minutes = (int)$date->format('i');

                if ($timestring->ordinal !== 'none' && $timestring->day !== 'none') {
                    $loopDate = $monthlyDate->modify($timestring->ordinal . ' ' . $timestring->day . ' of this month')->setTime($hours, $minutes);
                    break;
                }

                $loopDate = $monthlyDate;
                break;
            case 'daily':
            case 'yearly':
            default:
                $loopDate = $date;
                break;
        }

        return is_array($loopDate) ? $loopDate : DateTimeHelper::toDateTime($loopDate);
    }
}
