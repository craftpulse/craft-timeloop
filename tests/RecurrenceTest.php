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
use craftpulse\timeloop\services\OccurrenceIndexService;

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

it('truncates occurrencesBetween to the given limit', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=6',
    ]);

    $occurrences = $model->occurrencesBetween(
        brussels('2026-01-05T09:00:00'),
        brussels('2026-02-09T09:00:00'),
        2,
    );

    expect(ymd($occurrences))->toBe([
        '2026-01-05',
        '2026-01-12',
    ]);
});

// firstOccurrence
// -------------------------------------------------------------------------

it('returns the first occurrence of the set', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
    ]);

    expect($model->firstOccurrence()->format('Y-m-d'))->toBe('2026-01-05');
});

// Daylight saving: spring-forward gap
// -------------------------------------------------------------------------

it('normalizes a spring-forward skipped wall-clock time forward', function() {
    $model = recurrence([
        'dtstart' => '2026-03-22T02:30:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=SU;COUNT=4',
    ]);

    $occurrences = $model->occurrences();

    // 2026-03-29 falls inside the spring-forward gap (local clocks jump from
    // 02:00 to 03:00 that day). PHP's DateTime normalizes the invalid 02:30
    // wall-clock time forward to 03:30 instead of erroring, and the library
    // carries that normalization straight through.
    expect($occurrences[0]->format('Y-m-d H:i:s P'))->toBe('2026-03-22 02:30:00 +01:00')
        ->and($occurrences[1]->format('Y-m-d H:i:s P'))->toBe('2026-03-29 03:30:00 +02:00')
        ->and($occurrences[2]->format('Y-m-d H:i:s P'))->toBe('2026-04-05 02:30:00 +02:00')
        ->and($occurrences[3]->format('Y-m-d H:i:s P'))->toBe('2026-04-12 02:30:00 +02:00');
});

// activeAt: window rollover and edge cases
// -------------------------------------------------------------------------

it('rolls the active window into the next day when endTime is before the start time', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T22:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=2',
        'endTime' => '02:00',
    ]);

    expect($model->activeAt(brussels('2026-01-06T01:00:00')))->toBeTrue()
        ->and($model->activeAt(brussels('2026-01-06T03:00:00')))->toBeFalse();
});

it('treats an endTime of 00:00 as rolling to the following midnight', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=2',
        'endTime' => '00:00',
    ]);

    expect($model->activeAt(brussels('2026-01-05T23:59:59')))->toBeTrue()
        ->and($model->activeAt(brussels('2026-01-06T00:00:00')))->toBeFalse();
});

it('reports inactive before the first occurrence of a future-dated rule', function() {
    $model = recurrence([
        'dtstart' => '2026-06-01T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
    ]);

    expect($model->activeAt(brussels('2026-01-01T00:00:00')))->toBeFalse();
});

// Infinite rules
// -------------------------------------------------------------------------

it('caps an infinite rule at DEFAULT_LIMIT when no limit is given', function() {
    $model = recurrence([
        'dtstart' => '2026-01-01T00:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=DAILY',
    ]);

    expect($model->occurrences())->toHaveCount(RecurrenceModel::DEFAULT_LIMIT)
        ->and($model->occurrences(null))->toHaveCount(RecurrenceModel::DEFAULT_LIMIT);
});

it('caps an infinite rule at DEFAULT_LIMIT when occurrences(0) is called', function() {
    $model = recurrence([
        'dtstart' => '2026-01-01T00:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=DAILY',
    ]);

    // Regression: occurrences(0) used to reach the library's LogicException
    // guard because the old cap only fired for a null limit, not a falsy 0.
    expect($model->occurrences(0))->toHaveCount(RecurrenceModel::DEFAULT_LIMIT);
});

it('supports nextOccurrence on an infinite rule', function() {
    $model = recurrence([
        'dtstart' => '2026-01-01T00:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=DAILY',
    ]);

    expect($model->nextOccurrence(new DateTimeImmutable('2026-01-01T00:00:00', new DateTimeZone('UTC')))->format('Y-m-d'))->toBe('2026-01-02');
});

it('supports activeAt on an infinite rule', function() {
    $model = recurrence([
        'dtstart' => '2026-01-01T00:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=DAILY',
    ]);

    expect($model->activeAt(new DateTimeImmutable('2026-01-05T12:00:00', new DateTimeZone('UTC'))))->toBeTrue();
});

// Extra exclusions: mutation and memoization
// -------------------------------------------------------------------------

it('accepts extraExclusions from the config array via the magic setter', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
        'extraExclusions' => ['2026-01-19'],
    ]);

    expect($model->getExtraExclusions())->toBe(['2026-01-19']);
});

it('picks up extra exclusions injected after a first expansion', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
    ]);

    // First expansion memoizes the recurrence set before any exclusion is known.
    expect(ymd($model->occurrences()))->toBe([
        '2026-01-05',
        '2026-01-12',
        '2026-01-19',
        '2026-01-26',
    ]);

    $model->setExtraExclusions(['2026-01-19']);

    // The setter must reset the memoized set, or this would still return all 4 dates.
    expect(ymd($model->occurrences()))->toBe([
        '2026-01-05',
        '2026-01-12',
        '2026-01-26',
    ]);
});

// Timezone-mismatched input
// -------------------------------------------------------------------------

it('resolves activeAt and nextOccurrence correctly when the input date-time is in a different timezone', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
        'endTime' => '11:00',
    ]);

    // 2026-01-12T09:00:00+01:00 (Brussels, winter time) is 2026-01-12T08:00:00Z (UTC).
    $utcInstant = new DateTimeImmutable('2026-01-12T08:00:00', new DateTimeZone('UTC'));

    expect($model->activeAt($utcInstant))->toBeTrue()
        ->and($model->nextOccurrence($utcInstant)->format('Y-m-d'))->toBe('2026-01-19');
});

// Human-readable summary
// -------------------------------------------------------------------------
// Only the model method lands this phase; full Twig/GQL exposure and the
// plugin translation files are Phase 5. `humanReadable()` ships its own
// locale catalogue, so `en`/`nl`/`fr` render without any plugin translations.

it('renders an English summary of the rule', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=5',
    ]);

    expect($model->summary('en'))->toContain('weekly')
        ->and($model->summary('en'))->toContain('Monday');
});

it('passes the locale through to the library catalogue', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=5',
    ]);

    expect($model->summary('nl'))->toContain('wekelijks')
        ->and($model->summary('nl'))->not->toBe($model->summary('en'));
});

it('falls back to English wording for a locale the catalogue lacks', function() {
    // `is` (Icelandic) is a valid locale intl can construct, but the library
    // ships no `is` catalogue, so the rule wording falls back to English.
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=5',
    ]);

    expect($model->summary('is'))->toContain('weekly');
});

it('returns null when there is no rule to summarize', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'UTC',
        'rrule' => null,
    ]);

    expect($model->summary())->toBeNull();
});

it('matches occursAt regardless of the input timezone or DateTime mutability', function() {
    // rlanvin/php-rrule's RRule::occursAt() converts the input to the rule's
    // timezone via `$date->setTimezone(...)` without reassigning the result —
    // a silent no-op for DateTimeImmutable. RecurrenceModel::occursAt()
    // compensates by normalizing every input to a mutable DateTime in the
    // stored timezone before delegating, so any DateTimeInterface in any
    // timezone matches by instant.
    $model = recurrence([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
    ]);

    // 2026-01-12T09:00:00+01:00 (Brussels, winter time) is 2026-01-12T08:00:00Z (UTC).
    $utcImmutable = new DateTimeImmutable('2026-01-12T08:00:00', new DateTimeZone('UTC'));
    $utcMutable = new DateTime('2026-01-12T08:00:00', new DateTimeZone('UTC'));

    expect($model->occursAt($utcImmutable))->toBeTrue()
        ->and($model->occursAt($utcMutable))->toBeTrue()
        ->and($model->occursAt(brussels('2026-01-12T09:00:00')))->toBeTrue()
        ->and($model->occursAt(new DateTimeImmutable('2026-01-12T09:00:00', new DateTimeZone('UTC'))))->toBeFalse();
});

// currentOccurrence
// -------------------------------------------------------------------------

it('returns the in-progress occurrence start within a timed window', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T19:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
        'endTime' => '21:00',
    ]);

    expect($model->currentOccurrence(brussels('2026-01-05T20:00:00'))?->format('Y-m-d H:i'))->toBe('2026-01-05 19:00')
        ->and($model->currentOccurrence(brussels('2026-01-05T19:00:00'))?->format('Y-m-d H:i'))->toBe('2026-01-05 19:00')
        ->and($model->currentOccurrence(brussels('2026-01-05T21:00:00')))->toBeNull()
        ->and($model->currentOccurrence(brussels('2026-01-05T18:00:00')))->toBeNull();
});

it('returns the current all-day occurrence start', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T00:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
    ]);

    expect($model->currentOccurrence(brussels('2026-01-05T14:00:00'))?->format('Y-m-d'))->toBe('2026-01-05')
        ->and($model->currentOccurrence(brussels('2026-01-06T00:00:00')))->toBeNull();
});

// occurrenceRows
// -------------------------------------------------------------------------

it('derives timed [start, end) window rows', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T19:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=3',
        'endTime' => '21:00',
    ]);

    $rows = $model->occurrenceRows(brussels('2026-01-01T00:00:00'), brussels('2026-02-01T00:00:00'));

    expect(array_map(fn(array $r) => [
        $r['start']->format('Y-m-d H:i'),
        $r['end']->format('Y-m-d H:i'),
    ], $rows))->toBe([
        ['2026-01-05 19:00', '2026-01-05 21:00'],
        ['2026-01-12 19:00', '2026-01-12 21:00'],
        ['2026-01-19 19:00', '2026-01-19 21:00'],
    ]);
});

it('derives all-day rows spanning to the following midnight', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T00:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=DAILY;COUNT=2',
    ]);

    $rows = $model->occurrenceRows(brussels('2026-01-01T00:00:00'), brussels('2026-01-31T00:00:00'));

    expect(array_map(fn(array $r) => [
        $r['start']->format('Y-m-d H:i'),
        $r['end']->format('Y-m-d H:i'),
    ], $rows))->toBe([
        ['2026-01-05 00:00', '2026-01-06 00:00'],
        ['2026-01-06 00:00', '2026-01-07 00:00'],
    ]);
});

it('caps infinite-rule rows at the horizon and omits excluded rows', function() {
    $model = recurrence([
        'dtstart' => '2026-01-05T19:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
        'endTime' => '21:00',
    ]);
    // Exclude the second Monday; the horizon caps expansion at three weeks.
    $model->setExtraExclusions(['2026-01-12']);

    $rows = $model->occurrenceRows(brussels('2026-01-01T00:00:00'), brussels('2026-01-26T00:00:00'));

    expect(array_map(fn(array $r) => $r['start']->format('Y-m-d'), $rows))->toBe([
        '2026-01-05',
        '2026-01-19',
    ]);
});

// occurrenceRows: inline/queue threshold boundary
// -------------------------------------------------------------------------
// Pins the pure expansion semantics that
// OccurrenceIndexService::handleElementSave() relies on for its inline-vs-queue
// decision: it calls occurrenceRows($from, $to, INLINE_THRESHOLD + 1) and
// defers to the queue only when the result exceeds INLINE_THRESHOLD. That
// decision is only correct if the `+1`-capped limit returns precisely
// INLINE_THRESHOLD + 1 rows for a series with more occurrences than the
// threshold in range, and precisely the series' own count when it has exactly
// INLINE_THRESHOLD or fewer. No Craft app is booted; only the constant is
// referenced from OccurrenceIndexService, and the recurrence engine is exercised
// directly the same way as the rest of this file.

it('caps at INLINE_THRESHOLD + 1 rows for a series with more occurrences than the threshold, crossing the queue boundary', function() {
    $model = recurrence([
        'dtstart' => '2026-01-01T00:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=DAILY',
    ]);

    $rows = $model->occurrenceRows(
        new DateTimeImmutable('2026-01-01T00:00:00', new DateTimeZone('UTC')),
        new DateTimeImmutable('2030-01-01T00:00:00', new DateTimeZone('UTC')),
        OccurrenceIndexService::INLINE_THRESHOLD + 1,
    );

    expect($rows)->toHaveCount(OccurrenceIndexService::INLINE_THRESHOLD + 1)
        ->and(count($rows) > OccurrenceIndexService::INLINE_THRESHOLD)->toBeTrue();
});

it('returns exactly INLINE_THRESHOLD rows for a series with precisely that many occurrences, staying on the inline side of the boundary', function() {
    $model = recurrence([
        'dtstart' => '2026-01-01T00:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=DAILY;COUNT=' . OccurrenceIndexService::INLINE_THRESHOLD,
    ]);

    $rows = $model->occurrenceRows(
        new DateTimeImmutable('2026-01-01T00:00:00', new DateTimeZone('UTC')),
        new DateTimeImmutable('2030-01-01T00:00:00', new DateTimeZone('UTC')),
        OccurrenceIndexService::INLINE_THRESHOLD + 1,
    );

    expect($rows)->toHaveCount(OccurrenceIndexService::INLINE_THRESHOLD)
        ->and(count($rows) > OccurrenceIndexService::INLINE_THRESHOLD)->toBeFalse();
});
