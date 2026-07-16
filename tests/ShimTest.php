<?php
/**
 * Timeloop plugin for Craft CMS 5.x
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) CraftPulse
 */

// =========================================================================
// BACKWARDS-COMPATIBILITY SHIM
// =========================================================================
// Proves the v2-backed shim reproduces the 5.0.0 engine's output. The
// baseline is a faithful port of the 5.0.0 TimeloopService date logic
// (git show 5.0.0:src/services/TimeloopService.php), operating on DateTime
// objects in a fixed timezone so no Craft app is needed. The "actual" side
// runs the real TimeloopService::getLoop() over a real TimeloopModel built
// from the normalized value, so the mapping + RecurrenceModel engine are
// exercised end to end. Fixtures use a future start year so the futureDates
// filter is deterministic.

use craftpulse\timeloop\models\PeriodModel;
use craftpulse\timeloop\models\TimeloopModel;
use craftpulse\timeloop\models\TimeStringModel;
use craftpulse\timeloop\models\ValueNormalizer;
use craftpulse\timeloop\services\TimeloopService;

// Helpers
// -------------------------------------------------------------------------

const SHIM_TZ = 'Europe/Brussels';

/**
 * Parses a value into a Europe/Brussels DateTime.
 */
function bx(string $value): DateTime
{
    return (new DateTime($value))->setTimezone(new DateTimeZone(SHIM_TZ));
}

/**
 * Runs the real shim: normalize -> v2 -> service expansion.
 */
function shim(array $legacy, int $limit = 0, bool $futureDates = true): array
{
    $model = new TimeloopModel(ValueNormalizer::normalize($legacy, new DateTimeZone(SHIM_TZ)));
    $dates = (new TimeloopService())->getLoop($model, $limit, $futureDates) ?? [];

    return array_map(fn(DateTime $d) => $d->format('c'), $dates);
}

/**
 * Runs the ported 5.0.0 engine over the same legacy fixture.
 */
function baseline(array $legacy, int $limit = 0, bool $futureDates = true): array
{
    $start = bx($legacy['loopStartDate']);
    $end = isset($legacy['loopEndDate']) && $legacy['loopEndDate'] !== null
        ? bx($legacy['loopEndDate'])
        : (clone $start)->modify('+20 years');
    $period = new PeriodModel($legacy['loopPeriod']);
    $limit = $limit === 0 ? TimeloopService::MAX_ARRAY_ENTRIES : $limit;

    $dates = legacyFetch($start, $end, $period, $limit, $futureDates, new DateTime());

    return array_map(fn(DateTime $d) => $d->format('c'), $dates);
}

/**
 * Port of 5.0.0 TimeloopService::_calculateInterval().
 */
function legacyInterval(PeriodModel $period): object
{
    $cycle = max(1, $period->cycle);

    return match ($period->frequency) {
        'P1W' => (object)['interval' => "P{$cycle}W", 'frequency' => 'weekly'],
        'P1M' => (object)['interval' => "P{$cycle}M", 'frequency' => 'monthly'],
        'P1Y' => (object)['interval' => "P{$cycle}Y", 'frequency' => 'yearly'],
        default => (object)['interval' => "P{$cycle}D", 'frequency' => 'daily'],
    };
}

/**
 * Port of 5.0.0 TimeloopService::_monthCorrection().
 */
function legacyMonthCorrection(DateTime $date, int $months, int $cycle): DateTime
{
    $frequency = $months * $cycle;
    $date1 = clone $date;
    $date2 = clone $date;
    $addedMonths = clone $date1->modify($frequency . ' Month');

    if ($date2 != $date1->modify($frequency * -1 . ' Month')) {
        return $addedMonths->modify('last day of last month');
    }

    if ($date == $date2->modify('last day of this month')) {
        return $addedMonths->modify('last day of this month');
    }

    return $addedMonths;
}

/**
 * Port of 5.0.0 TimeloopService::_parseDate().
 *
 * @return DateTime|DateTime[]
 */
function legacyParseDate(string $frequency, DateTime $date, DateTime $end, int $counter, PeriodModel $period, TimeStringModel $timestring): DateTime|array
{
    switch ($frequency) {
        case 'weekly':
            if (count($period->days) === 0) {
                return $date;
            }

            $weekDates = [];
            $hours = (int)$date->format('H');
            $minutes = (int)$date->format('i');

            foreach ($period->days as $day) {
                $weekDay = (clone $date)->modify(strtolower($day) . ' this week')->setTime($hours, $minutes);

                if ($weekDay <= $end) {
                    $weekDates[] = $weekDay;
                }
            }

            return $weekDates;
        case 'monthly':
            $monthlyDate = legacyMonthCorrection($date, $counter, $period->cycle);
            $hours = (int)$date->format('H');
            $minutes = (int)$date->format('i');

            if ($timestring->ordinal !== 'none' && $timestring->day !== 'none') {
                return $monthlyDate->modify($timestring->ordinal . ' ' . $timestring->day . ' of this month')->setTime($hours, $minutes);
            }

            return $monthlyDate;
        default:
            return $date;
    }
}

/**
 * Port of 5.0.0 TimeloopService::_fetchDates().
 */
function legacyFetch(DateTime $start, DateTime $end, PeriodModel $period, int $limit, bool $futureDates, DateTime $now): array
{
    $interval = legacyInterval($period);
    $timestring = new TimeStringModel($period->timestring);
    $datePeriod = new DatePeriod($start, new DateInterval($interval->interval), $end);
    $arrDates = [];
    $counter = 0;

    foreach ($datePeriod as $date) {
        $dateToParse = $interval->frequency === 'monthly' ? $start : $date;
        $loopDates = legacyParseDate($interval->frequency, $dateToParse, $end, $counter, $period, $timestring);

        if (!is_array($loopDates)) {
            $loopDates = [$loopDates];
        }

        foreach ($loopDates as $loopDate) {
            if ($loopDate < $start) {
                continue;
            }

            if ($futureDates && $loopDate <= $now) {
                continue;
            }

            $arrDates[] = $loopDate;
        }

        if ($limit > 0 && count($arrDates) >= $limit) {
            break;
        }

        $counter++;
    }

    return $limit > 0 ? array_slice($arrDates, 0, $limit) : $arrDates;
}

/**
 * Builds a 5.0.0-format legacy fixture (times baked into the dates).
 */
function fixture(array $overrides = []): array
{
    return array_merge([
        'loopStartDate' => '2030-01-07T09:00:00+01:00',
        'loopEndDate' => null,
        'loopStartTime' => null,
        'loopEndTime' => null,
        'loopReminderValue' => 0,
        'loopReminderPeriod' => null,
        'loopPeriod' => ['frequency' => 'P1D', 'cycle' => 1, 'days' => [], 'timestring' => ['ordinal' => 'none', 'day' => 'none']],
    ], $overrides);
}

// Date expansion parity
// -------------------------------------------------------------------------

it('matches 5.0.0 for a daily loop', function() {
    $fixture = fixture(['loopEndDate' => '2030-01-20T23:59:00+01:00']);

    expect(shim($fixture, 0, false))->toBe(baseline($fixture, 0, false));
});

it('matches 5.0.0 for a daily loop with an interval', function() {
    $fixture = fixture([
        'loopEndDate' => '2030-03-31T23:59:00+02:00',
        'loopPeriod' => ['frequency' => 'P1D', 'cycle' => 3, 'days' => [], 'timestring' => []],
    ]);

    expect(shim($fixture, 0, false))->toBe(baseline($fixture, 0, false));
});

it('matches 5.0.0 for a weekly loop with days', function() {
    // Bounded by a limit (not an end date) so the end-boundary divergence
    // documented below does not enter into the series comparison.
    $fixture = fixture([
        'loopStartDate' => '2030-09-07T20:00:00+02:00',
        'loopPeriod' => ['frequency' => 'P1W', 'cycle' => 1, 'days' => ['Monday', 'Friday'], 'timestring' => []],
    ]);

    expect(shim($fixture, 20, false))->toBe(baseline($fixture, 20, false));
});

it('matches 5.0.0 for a monthly first-Monday timestring', function() {
    $fixture = fixture([
        'loopStartDate' => '2030-01-01T18:00:00+01:00',
        'loopEndDate' => '2030-12-31T23:59:00+01:00',
        'loopPeriod' => ['frequency' => 'P1M', 'cycle' => 1, 'days' => [], 'timestring' => ['ordinal' => 'first', 'day' => 'monday']],
    ]);

    expect(shim($fixture, 0, false))->toBe(baseline($fixture, 0, false));
});

it('matches 5.0.0 for a monthly last-Saturday timestring', function() {
    $fixture = fixture([
        'loopStartDate' => '2030-01-01T18:00:00+01:00',
        'loopEndDate' => '2030-12-31T23:59:00+01:00',
        'loopPeriod' => ['frequency' => 'P1M', 'cycle' => 1, 'days' => [], 'timestring' => ['ordinal' => 'last', 'day' => 'saturday']],
    ]);

    expect(shim($fixture, 0, false))->toBe(baseline($fixture, 0, false));
});

it('matches 5.0.0 for a month-end monthly loop', function() {
    // Bounded by a limit so only the drift series (not the end boundary) is
    // compared. 5.0.0 drifted a month-end start to the last day of every month.
    $fixture = fixture([
        'loopStartDate' => '2030-01-31T09:00:00+01:00',
        'loopPeriod' => ['frequency' => 'P1M', 'cycle' => 1, 'days' => [], 'timestring' => ['ordinal' => 'none', 'day' => 'none']],
    ]);

    expect(shim($fixture, 13, false))
        ->toBe(baseline($fixture, 13, false))
        ->and(shim($fixture, 13, false))->toContain('2030-02-28T09:00:00+01:00', '2030-04-30T09:00:00+02:00', '2031-01-31T09:00:00+01:00');
});

it('matches 5.0.0 for a yearly loop', function() {
    $fixture = fixture([
        'loopStartDate' => '2030-02-15T09:00:00+01:00',
        'loopEndDate' => '2040-12-31T23:59:00+01:00',
        'loopPeriod' => ['frequency' => 'P1Y', 'cycle' => 1, 'days' => [], 'timestring' => []],
    ]);

    expect(shim($fixture, 0, false))->toBe(baseline($fixture, 0, false));
});

// No end date: 20-year horizon + 100 cap
// -------------------------------------------------------------------------

it('caps an infinite daily loop at 100 dates like 5.0.0', function() {
    $dates = shim(fixture(), 0, false);

    expect($dates)->toHaveCount(100)
        ->and($dates)->toBe(baseline(fixture(), 0, false));
});

it('bounds an infinite yearly loop by the 20-year horizon', function() {
    $fixture = fixture(['loopPeriod' => ['frequency' => 'P1Y', 'cycle' => 1, 'days' => [], 'timestring' => []]]);
    $dates = shim($fixture, 0, false);

    // 20-year horizon, not the 100 cap: far fewer than 100 dates.
    expect(count($dates))->toBeLessThan(100)
        ->and(array_slice($dates, 0, 15))->toBe(array_slice(baseline($fixture, 0, false), 0, 15));
});

// Limit and futureDates arguments
// -------------------------------------------------------------------------

it('honours the limit argument', function() {
    $fixture = fixture(['loopEndDate' => '2030-12-31T23:59:00+01:00']);

    expect(shim($fixture, 5, false))
        ->toHaveCount(5)
        ->toBe(baseline($fixture, 5, false));
});

it('returns only future dates when futureDates is true', function() {
    // A wholly future fixture: futureDates=true must equal futureDates=false.
    $fixture = fixture(['loopEndDate' => '2030-01-20T23:59:00+01:00']);

    expect(shim($fixture, 0, true))->toBe(shim($fixture, 0, false));
});

// Derived getters
// -------------------------------------------------------------------------

it('derives the period, timestring, times and reminder from v2', function() {
    $model = new TimeloopModel(ValueNormalizer::normalize(fixture([
        'loopStartDate' => '2030-09-07T20:00:00+02:00',
        'loopEndDate' => '2031-06-30T22:00:00+02:00',
        'loopEndTime' => '2030-01-01T22:00:00+01:00',
        'loopReminderValue' => 2,
        'loopReminderPeriod' => 'days',
        'loopPeriod' => ['frequency' => 'P1W', 'cycle' => 1, 'days' => ['Monday'], 'timestring' => ['ordinal' => 'none', 'day' => 'none']],
    ]), new DateTimeZone(SHIM_TZ)));

    expect($model->getPeriod()->frequency)->toBe('P1W')
        ->and($model->getPeriod()->days)->toBe(['Monday'])
        ->and($model->getLoopStartTime())->toBe('20:00')
        ->and($model->getLoopEndTime())->toBe('22:00')
        ->and($model->loopStartDate->format('c'))->toBe('2030-09-07T20:00:00+02:00')
        ->and($model->loopEndDate->format('c'))->toBe('2031-06-30T22:00:00+02:00')
        ->and($model->loopReminderValue)->toBe(2)
        ->and($model->loopReminderPeriod)->toBe('days');
});

it('derives a null timestring model for a weekly loop', function() {
    $model = new TimeloopModel(ValueNormalizer::normalize(fixture([
        'loopPeriod' => ['frequency' => 'P1W', 'cycle' => 1, 'days' => ['Monday'], 'timestring' => ['ordinal' => 'none', 'day' => 'none']],
    ]), new DateTimeZone(SHIM_TZ)));

    expect($model->getTimeString()->ordinal)->toBe('none');
});

it('derives a positional timestring model for a monthly loop', function() {
    $model = new TimeloopModel(ValueNormalizer::normalize(fixture([
        'loopStartDate' => '2030-01-01T09:00:00+01:00',
        'loopPeriod' => ['frequency' => 'P1M', 'cycle' => 1, 'days' => [], 'timestring' => ['ordinal' => 'first', 'day' => 'monday']],
    ]), new DateTimeZone(SHIM_TZ)));

    expect($model->getTimeString()->ordinal)->toBe('first')
        ->and($model->getTimeString()->day)->toBe('monday');
});

it('computes the reminder date from the first upcoming occurrence', function() {
    $model = new TimeloopModel(ValueNormalizer::normalize(fixture([
        'loopStartDate' => '2030-09-09T20:00:00+02:00',
        'loopReminderValue' => 2,
        'loopReminderPeriod' => 'days',
        'loopPeriod' => ['frequency' => 'P1W', 'cycle' => 1, 'days' => ['Monday'], 'timestring' => []],
    ]), new DateTimeZone(SHIM_TZ)));

    // First Monday on/after 2030-09-09 is 2030-09-09 (a Monday); minus 2 days.
    expect((new TimeloopService())->getReminder($model)->format('Y-m-d'))->toBe('2030-09-07');
});

// Empty model
// -------------------------------------------------------------------------

it('returns null dates for an empty model', function() {
    $model = new TimeloopModel(ValueNormalizer::normalize([], new DateTimeZone(SHIM_TZ)));

    expect($model->isEmpty())->toBeTrue()
        ->and((new TimeloopService())->getLoop($model))->toBeNull()
        ->and($model->getPeriod())->toBeNull();
});

// Documented end-boundary divergence
// -------------------------------------------------------------------------

it('includes the final on-boundary occurrence that 5.0.0 could drop', function() {
    // KNOWN, DOCUMENTED DIVERGENCE (see the plan report). 5.0.0 expanded via a
    // PHP DatePeriod whose raw week/month stepping (with an exclusive end) lags
    // the corrected occurrence date, so an occurrence landing exactly on
    // loopEndDate could be dropped. The RRULE `UNTIL` is inclusive by instant,
    // so the v2 shim keeps it. The shim is the more correct of the two, and the
    // interior series is identical (proved by the limit-bounded tests above).
    $fixture = fixture([
        'loopStartDate' => '2030-01-31T09:00:00+01:00',
        'loopEndDate' => '2031-01-31T23:59:00+01:00',
        'loopPeriod' => ['frequency' => 'P1M', 'cycle' => 1, 'days' => [], 'timestring' => ['ordinal' => 'none', 'day' => 'none']],
    ]);

    expect(shim($fixture, 0, false))->toContain('2031-01-31T09:00:00+01:00')
        ->and(baseline($fixture, 0, false))->not->toContain('2031-01-31T09:00:00+01:00');
});
