<?php
/**
 * Timeloop plugin for Craft CMS 5.x
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) CraftPulse
 */

// =========================================================================
// RECURRENCE ENGINE
// =========================================================================
// Standalone coverage for the headless recurrence core. No Craft app is
// booted; the model is exercised directly with fixed dates.

use craftpulse\timeloop\models\RecurrenceModel;

// Helpers
// -------------------------------------------------------------------------

/**
 * Builds a recurrence model from the v2 storage shape.
 */
function recurrence(array $config): RecurrenceModel
{
    return new RecurrenceModel($config);
}

/**
 * Builds a Europe/Brussels date-time for assertions and arguments.
 */
function brussels(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone('Europe/Brussels'));
}

/**
 * Maps occurrences to `Y-m-d` strings.
 */
function ymd(array $occurrences): array
{
    return array_map(fn(DateTimeInterface $d) => $d->format('Y-m-d'), $occurrences);
}

// Frequency expansion
// -------------------------------------------------------------------------

it('drifts to month-end without inventing short-month days', function() {
    $model = recurrence([
        'dtstart' => '2020-01-31T00:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=MONTHLY;COUNT=5',
    ]);

    expect(ymd($model->occurrences()))->toBe([
        '2020-01-31',
        '2020-03-31',
        '2020-05-31',
        '2020-07-31',
        '2020-08-31',
    ]);
});

it('only lands on leap days for a Feb 29 yearly rule', function() {
    $model = recurrence([
        'dtstart' => '2020-02-29T00:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=YEARLY;COUNT=3',
    ]);

    expect(ymd($model->occurrences()))->toBe([
        '2020-02-29',
        '2024-02-29',
        '2028-02-29',
    ]);
});

it('expands a weekly rule across multiple by-days', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE,FR;COUNT=6',
    ]);

    expect(ymd($model->occurrences()))->toBe([
        '2026-01-05',
        '2026-01-07',
        '2026-01-09',
        '2026-01-12',
        '2026-01-14',
        '2026-01-16',
    ]);
});

it('expands a monthly first-Monday rule', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=MONTHLY;BYDAY=1MO;COUNT=3',
    ]);

    expect(ymd($model->occurrences()))->toBe([
        '2026-01-05',
        '2026-02-02',
        '2026-03-02',
    ]);
});

it('expands a monthly last-Saturday rule', function() {
    $model = recurrence([
        'dtstart' => '2026-01-31T09:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=MONTHLY;BYDAY=-1SA;COUNT=3',
    ]);

    expect(ymd($model->occurrences()))->toBe([
        '2026-01-31',
        '2026-02-28',
        '2026-03-28',
    ]);
});

it('honours an interval greater than one', function() {
    $model = recurrence([
        'dtstart' => '2026-01-01T00:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=DAILY;INTERVAL=3;COUNT=4',
    ]);

    expect(ymd($model->occurrences()))->toBe([
        '2026-01-01',
        '2026-01-04',
        '2026-01-07',
        '2026-01-10',
    ]);
});

// End conditions
// -------------------------------------------------------------------------

it('stops after COUNT occurrences', function() {
    $model = recurrence([
        'dtstart' => '2026-01-01T00:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=DAILY;COUNT=5',
    ]);

    $occurrences = $model->occurrences();

    expect($occurrences)->toHaveCount(5)
        ->and($occurrences[0]->format('Y-m-d'))->toBe('2026-01-01')
        ->and($occurrences[4]->format('Y-m-d'))->toBe('2026-01-05');
});

it('stops at a UTC UNTIL boundary', function() {
    $model = recurrence([
        'dtstart' => '2020-01-01T00:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=DAILY;UNTIL=20200105T120000Z',
    ]);

    $occurrences = $model->occurrences();

    expect($occurrences)->toHaveCount(5)
        ->and($occurrences[4]->format('Y-m-d'))->toBe('2020-01-05');
});

// Daylight saving
// -------------------------------------------------------------------------

it('keeps wall-clock time constant across spring-forward', function() {
    $model = recurrence([
        'dtstart' => '2026-03-15T19:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=SU;COUNT=4',
    ]);

    $occurrences = $model->occurrences();

    foreach ($occurrences as $occurrence) {
        expect($occurrence->format('H:i'))->toBe('19:00')
            ->and($occurrence->getTimezone()->getName())->toBe('Europe/Brussels');
    }

    // Mar 22 is before the Mar 29 transition, Apr 5 is after it.
    expect($occurrences[1]->format('P'))->toBe('+01:00')
        ->and($occurrences[3]->format('P'))->toBe('+02:00');
});

it('keeps wall-clock time constant across fall-back', function() {
    $model = recurrence([
        'dtstart' => '2026-10-11T19:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=SU;COUNT=4',
    ]);

    $occurrences = $model->occurrences();

    foreach ($occurrences as $occurrence) {
        expect($occurrence->format('H:i'))->toBe('19:00');
    }

    // Oct 18 is before the Oct 25 transition, Nov 1 is after it.
    expect($occurrences[1]->format('P'))->toBe('+02:00')
        ->and($occurrences[3]->format('P'))->toBe('+01:00');
});

// Exclusions and extras
// -------------------------------------------------------------------------

it('removes an EXDATE from the set', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
        'exdates' => ['2026-01-12'],
    ]);

    expect(ymd($model->occurrences()))->toBe([
        '2026-01-05',
        '2026-01-19',
        '2026-01-26',
    ]);
});

it('adds an RDATE to the set', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=2',
        'rdates' => ['2026-01-08'],
    ]);

    expect(ymd($model->occurrences()))->toBe([
        '2026-01-05',
        '2026-01-08',
        '2026-01-12',
    ]);
});

it('removes injected extra exclusions', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
        'extraExclusions' => ['2026-01-19'],
    ]);

    expect(ymd($model->occurrences()))->toBe([
        '2026-01-05',
        '2026-01-12',
        '2026-01-26',
    ]);
});

// occursAt
// -------------------------------------------------------------------------

it('matches an occurrence by exact instant', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
    ]);

    expect($model->occursAt(brussels('2026-01-12T09:00:00')))->toBeTrue()
        ->and($model->occursAt(brussels('2026-01-13T09:00:00')))->toBeFalse()
        ->and($model->occursAt(brussels('2026-01-12T10:00:00')))->toBeFalse();
});

// activeAt
// -------------------------------------------------------------------------

it('reports active within a timed occurrence window', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T19:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
        'endTime' => '21:00',
    ]);

    expect($model->activeAt(brussels('2026-01-05T20:00:00')))->toBeTrue()
        ->and($model->activeAt(brussels('2026-01-05T19:00:00')))->toBeTrue()
        ->and($model->activeAt(brussels('2026-01-05T18:00:00')))->toBeFalse()
        ->and($model->activeAt(brussels('2026-01-05T21:00:00')))->toBeFalse()
        ->and($model->activeAt(brussels('2026-01-05T21:30:00')))->toBeFalse();
});

it('reports active for the whole day of an all-day occurrence', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T00:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
    ]);

    expect($model->activeAt(brussels('2026-01-05T00:00:00')))->toBeTrue()
        ->and($model->activeAt(brussels('2026-01-05T14:00:00')))->toBeTrue()
        ->and($model->activeAt(brussels('2026-01-05T23:59:59')))->toBeTrue()
        ->and($model->activeAt(brussels('2026-01-06T00:00:00')))->toBeFalse()
        ->and($model->activeAt(brussels('2026-01-04T23:59:59')))->toBeFalse();
});

// nextOccurrence
// -------------------------------------------------------------------------

it('returns the first occurrence strictly after a reference date', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
    ]);

    expect($model->nextOccurrence(brussels('2026-01-10T09:00:00'))->format('Y-m-d'))->toBe('2026-01-12')
        ->and($model->nextOccurrence(brussels('2026-01-12T09:00:00'))->format('Y-m-d'))->toBe('2026-01-19')
        ->and($model->nextOccurrence(brussels('2026-01-26T09:00:00')))->toBeNull();
});

// occurrencesBetween
// -------------------------------------------------------------------------

it('includes both boundaries of a range', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=6',
    ]);

    $occurrences = $model->occurrencesBetween(
        brussels('2026-01-12T09:00:00'),
        brussels('2026-01-26T09:00:00'),
    );

    expect(ymd($occurrences))->toBe([
        '2026-01-12',
        '2026-01-19',
        '2026-01-26',
    ]);
});
