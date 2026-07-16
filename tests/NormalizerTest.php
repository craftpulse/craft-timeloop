<?php
/**
 * Timeloop plugin for Craft CMS 5.x
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) CraftPulse
 */

// =========================================================================
// VALUE NORMALIZER
// =========================================================================
// Standalone coverage for the legacy -> v2 mapping. No Craft app is booted;
// the timezone is injected and dates are fixed. Fixtures mirror the real
// content-store shape derived from the 4.1.1 / beta.3 / 5.0.0 serializers,
// which all wrote identical JSON (UTC ISO-8601 dates + a loopPeriod object).

use craftpulse\timeloop\models\ValueNormalizer;

// Helpers
// -------------------------------------------------------------------------

/**
 * Normalizes a legacy value against a fixed timezone.
 */
function normalize(array $value, string $timezone = 'UTC'): array
{
    return ValueNormalizer::normalize($value, new DateTimeZone($timezone));
}

/**
 * Builds a legacy stored value in the real content-store shape.
 */
function legacy(array $overrides = []): array
{
    return array_merge([
        'loopStartDate' => '2026-09-07T19:00:00+00:00',
        'loopEndDate' => null,
        'loopStartTime' => null,
        'loopEndTime' => null,
        'loopReminderValue' => 0,
        'loopReminderPeriod' => null,
        'loopPeriod' => [
            'frequency' => 'P1D',
            'cycle' => 1,
            'days' => [],
            'timestring' => ['ordinal' => 'none', 'day' => 'none'],
        ],
    ], $overrides);
}

// Frequency mapping
// -------------------------------------------------------------------------

it('maps a daily loop to FREQ=DAILY', function() {
    $v2 = normalize(legacy());

    expect($v2['version'])->toBe(2)
        ->and($v2['dtstart'])->toBe('2026-09-07T19:00:00')
        ->and($v2['timezone'])->toBe('UTC')
        ->and($v2['rrule'])->toBe('FREQ=DAILY');
});

it('maps a cycle greater than one to INTERVAL', function() {
    $v2 = normalize(legacy(['loopPeriod' => ['frequency' => 'P1D', 'cycle' => 3, 'days' => [], 'timestring' => []]]));

    expect($v2['rrule'])->toBe('FREQ=DAILY;INTERVAL=3');
});

it('maps a weekly loop with days to BYDAY', function() {
    $v2 = normalize(legacy(['loopPeriod' => [
        'frequency' => 'P1W',
        'cycle' => 1,
        'days' => ['Monday', 'Friday'],
        'timestring' => ['ordinal' => 'none', 'day' => 'none'],
    ]]));

    expect($v2['rrule'])->toBe('FREQ=WEEKLY;BYDAY=MO,FR');
});

it('maps a weekly loop without days to a bare FREQ=WEEKLY', function() {
    $v2 = normalize(legacy(['loopPeriod' => ['frequency' => 'P1W', 'cycle' => 1, 'days' => [], 'timestring' => []]]));

    expect($v2['rrule'])->toBe('FREQ=WEEKLY');
});

it('maps a yearly loop to FREQ=YEARLY', function() {
    $v2 = normalize(legacy(['loopPeriod' => ['frequency' => 'P1Y', 'cycle' => 1, 'days' => [], 'timestring' => []]]));

    expect($v2['rrule'])->toBe('FREQ=YEARLY');
});

// Monthly by-position
// -------------------------------------------------------------------------

it('maps a monthly first-Monday timestring to positional BYDAY', function() {
    $v2 = normalize(legacy(['loopPeriod' => [
        'frequency' => 'P1M',
        'cycle' => 1,
        'days' => [],
        'timestring' => ['ordinal' => 'first', 'day' => 'monday'],
    ]]));

    expect($v2['rrule'])->toBe('FREQ=MONTHLY;BYDAY=1MO');
});

it('maps a monthly last-Saturday timestring to a negative positional BYDAY', function() {
    $v2 = normalize(legacy(['loopPeriod' => [
        'frequency' => 'P1M',
        'cycle' => 1,
        'days' => [],
        'timestring' => ['ordinal' => 'last', 'day' => 'saturday'],
    ]]));

    expect($v2['rrule'])->toBe('FREQ=MONTHLY;BYDAY=-1SA');
});

it('ignores an ordinal of none and treats a non-month-end monthly as plain', function() {
    $v2 = normalize(legacy([
        'loopStartDate' => '2026-09-15T19:00:00+00:00',
        'loopPeriod' => ['frequency' => 'P1M', 'cycle' => 1, 'days' => [], 'timestring' => ['ordinal' => 'none', 'day' => 'none']],
    ]));

    expect($v2['rrule'])->toBe('FREQ=MONTHLY');
});

// Month-end
// -------------------------------------------------------------------------

it('maps a month-end monthly start to BYMONTHDAY=-1', function() {
    $v2 = normalize(legacy([
        'loopStartDate' => '2026-01-31T00:00:00+00:00',
        'loopPeriod' => ['frequency' => 'P1M', 'cycle' => 1, 'days' => [], 'timestring' => ['ordinal' => 'none', 'day' => 'none']],
    ]));

    expect($v2['rrule'])->toBe('FREQ=MONTHLY;BYMONTHDAY=-1');
});

it('treats a February month-end start as BYMONTHDAY=-1', function() {
    $v2 = normalize(legacy([
        'loopStartDate' => '2025-02-28T00:00:00+00:00',
        'loopPeriod' => ['frequency' => 'P1M', 'cycle' => 1, 'days' => [], 'timestring' => ['ordinal' => 'none', 'day' => 'none']],
    ]));

    expect($v2['rrule'])->toBe('FREQ=MONTHLY;BYMONTHDAY=-1');
});

// End date + times
// -------------------------------------------------------------------------

it('maps loopEndDate to a UTC UNTIL using the end time', function() {
    $v2 = normalize(legacy([
        'loopEndDate' => '2027-06-30T00:00:00+00:00',
        'loopEndTime' => '2026-01-01T21:00:00+00:00',
    ]));

    expect($v2['endTime'])->toBe('21:00')
        ->and($v2['rrule'])->toBe('FREQ=DAILY;UNTIL=20270630T210000Z');
});

it('defaults a missing end time to 23:59 for the UNTIL boundary', function() {
    $v2 = normalize(legacy(['loopEndDate' => '2027-06-30T00:00:00+00:00']));

    expect($v2['endTime'])->toBeNull()
        ->and($v2['rrule'])->toBe('FREQ=DAILY;UNTIL=20270630T235900Z');
});

it('merges a separate start time into dtstart', function() {
    $v2 = normalize(legacy([
        'loopStartDate' => '2026-09-07T00:00:00+00:00',
        'loopStartTime' => '2026-01-01T08:30:00+00:00',
    ]));

    expect($v2['dtstart'])->toBe('2026-09-07T08:30:00');
});

it('repairs the #62 bug by taking UNTIL from the end time, not the stored end date', function() {
    // 4.1.1 / beta.3 baked the START time (19:00) into loopEndDate; the real
    // end time is 21:00. The repaired UNTIL must carry 21:00, not 19:00.
    $v2 = normalize(legacy([
        'loopStartTime' => '2026-01-01T19:00:00+00:00',
        'loopEndDate' => '2027-06-30T19:00:00+00:00',
        'loopEndTime' => '2026-01-01T21:00:00+00:00',
    ]));

    expect($v2['rrule'])->toBe('FREQ=DAILY;UNTIL=20270630T210000Z');
});

// Reminder
// -------------------------------------------------------------------------

it('carries the reminder value and period', function() {
    $v2 = normalize(legacy(['loopReminderValue' => 2, 'loopReminderPeriod' => 'weeks']));

    expect($v2['reminder'])->toBe(['value' => 2, 'period' => 'weeks']);
});

it('nulls an empty reminder period', function() {
    $v2 = normalize(legacy(['loopReminderValue' => 0, 'loopReminderPeriod' => '']));

    expect($v2['reminder'])->toBe(['value' => 0, 'period' => null]);
});

// Holidays default
// -------------------------------------------------------------------------

it('stamps a disabled holidays object on migrated values', function() {
    expect(normalize(legacy())['holidays'])->toBe(['enabled' => false, 'country' => null, 'region' => null]);
});

// Timezone handling
// -------------------------------------------------------------------------

it('converts a stored UTC date into the injected local timezone', function() {
    // 18:00 UTC on Sept 7 is 20:00 in Brussels (CEST, +02:00).
    $v2 = normalize(legacy(['loopStartDate' => '2026-09-07T18:00:00+00:00']), 'Europe/Brussels');

    expect($v2['dtstart'])->toBe('2026-09-07T20:00:00')
        ->and($v2['timezone'])->toBe('Europe/Brussels');
});

// Empty / garbage input
// -------------------------------------------------------------------------

it('returns an empty value for a value without a start date', function() {
    expect(normalize([])['dtstart'])->toBeNull();
});

it('returns an empty value when the period is present but the start is missing', function() {
    $v2 = normalize(['loopPeriod' => ['frequency' => 'P1W', 'cycle' => 1, 'days' => ['Monday'], 'timestring' => []]]);

    expect($v2['dtstart'])->toBeNull()
        ->and($v2['rrule'])->toBeNull();
});

it('returns a null rrule when the start exists but no period does', function() {
    $v2 = normalize(['loopStartDate' => '2026-09-07T19:00:00+00:00']);

    expect($v2['dtstart'])->toBe('2026-09-07T19:00:00')
        ->and($v2['rrule'])->toBeNull();
});

it('defaults an out-of-vocabulary frequency to FREQ=DAILY (deliberate divergence from 5.0.0)', function() {
    // Only reachable through a free-string GraphQL mutation input; the control
    // panel always writes one of P1D/P1W/P1M/P1Y. 5.0.0 defaulted an unknown
    // legacy frequency to `yearly`; the v2 normalizer deliberately defaults to
    // `DAILY` instead (see the ValueNormalizer class docblock).
    $v2 = normalize(legacy(['loopPeriod' => ['frequency' => 'P1Q', 'cycle' => 1, 'days' => [], 'timestring' => []]]));

    expect($v2['rrule'])->toBe('FREQ=DAILY');
});

// Idempotency
// -------------------------------------------------------------------------

it('is idempotent: a v2 value normalizes to itself', function() {
    $once = normalize(legacy([
        'loopStartTime' => '2026-01-01T19:00:00+00:00',
        'loopEndDate' => '2027-06-30T00:00:00+00:00',
        'loopEndTime' => '2026-01-01T21:00:00+00:00',
        'loopReminderValue' => 2,
        'loopReminderPeriod' => 'days',
        'loopPeriod' => ['frequency' => 'P1W', 'cycle' => 1, 'days' => ['Monday'], 'timestring' => ['ordinal' => 'none', 'day' => 'none']],
    ]), 'Europe/Brussels');

    $twice = normalize($once, 'Europe/Brussels');

    expect($twice)->toBe($once);
});

// Reverse derivation
// -------------------------------------------------------------------------

it('derives a weekly loop period back out of an rrule', function() {
    $period = ValueNormalizer::rruleToLoopPeriod('FREQ=WEEKLY;BYDAY=MO,FR;UNTIL=20270630T235900Z');

    expect($period)->toBe([
        'frequency' => 'P1W',
        'cycle' => 1,
        'days' => ['Monday', 'Friday'],
        'timestring' => ['ordinal' => 'none', 'day' => 'none'],
    ]);
});

it('derives a monthly positional timestring back out of an rrule', function() {
    $period = ValueNormalizer::rruleToLoopPeriod('FREQ=MONTHLY;BYDAY=-1SA');

    expect($period)->toBe([
        'frequency' => 'P1M',
        'cycle' => 1,
        'days' => [],
        'timestring' => ['ordinal' => 'last', 'day' => 'saturday'],
    ]);
});

it('derives the interval back out of an rrule', function() {
    $period = ValueNormalizer::rruleToLoopPeriod('FREQ=DAILY;INTERVAL=3');

    expect($period['frequency'])->toBe('P1D')
        ->and($period['cycle'])->toBe(3);
});

it('returns a null loop period for an empty rrule', function() {
    expect(ValueNormalizer::rruleToLoopPeriod(null))->toBeNull();
});

// Reverse derivation: graceful degradation
// -------------------------------------------------------------------------

it('degrades an unknown FREQ to a P1D view without crashing', function() {
    $period = ValueNormalizer::rruleToLoopPeriod('FREQ=SECONDLY');

    expect($period['frequency'])->toBe('P1D')
        ->and($period['cycle'])->toBe(1)
        ->and($period['days'])->toBe([]);
});

it('skips a malformed BYDAY token without crashing', function() {
    $period = ValueNormalizer::rruleToLoopPeriod('FREQ=WEEKLY;BYDAY=MO,XX,FR');

    expect($period['days'])->toBe(['Monday', 'Friday']);
});

it('ignores unrecognized RRULE parts without crashing', function() {
    $period = ValueNormalizer::rruleToLoopPeriod('FREQ=WEEKLY;BYDAY=MO;COUNT=5;BYSETPOS=1;WKST=SU');

    expect($period['frequency'])->toBe('P1W')
        ->and($period['days'])->toBe(['Monday']);
});

// Migration predicate (needsUpgrade)
// -------------------------------------------------------------------------
// Pure logic shared with `m260716_000000_timeloop_v2_content`, extracted here
// so it is unit-testable without booting a Craft app / DB connection (see
// `_needsUpgrade()` -> `ValueNormalizer::needsUpgrade()`).

it('flags a legacy value (version below current) as needing upgrade', function() {
    expect(ValueNormalizer::needsUpgrade(legacy()))->toBeTrue();
});

it('does not flag a v2 value as needing upgrade', function() {
    expect(ValueNormalizer::needsUpgrade(normalize(legacy())))->toBeFalse();
});

it('does not flag a non-array value as needing upgrade', function() {
    expect(ValueNormalizer::needsUpgrade('{"still":"json-encoded"}'))->toBeFalse()
        ->and(ValueNormalizer::needsUpgrade(null))->toBeFalse();
});

it('does not flag an empty array as needing upgrade', function() {
    expect(ValueNormalizer::needsUpgrade([]))->toBeFalse();
});

// UI POST -> v2 (inputToV2)
// -------------------------------------------------------------------------
// The curated control-panel subset -> v2 mapping. Dates arrive already
// coerced to plain strings (the field layer does that with a booted Craft
// app), so this stays pure and the timezone is injected.

/**
 * Normalizes a UI input POST subset against a fixed timezone.
 */
function input(array $input, string $timezone = 'UTC'): array
{
    return ValueNormalizer::inputToV2($input, new DateTimeZone($timezone));
}

it('returns an empty value when the input carries no start date', function() {
    expect(input(['frequency' => 'DAILY'])['dtstart'])->toBeNull();
});

it('builds a daily rule and merges the start time into dtstart', function() {
    $v2 = input(['startDate' => '2026-09-07', 'startTime' => '19:00', 'frequency' => 'DAILY']);

    expect($v2['version'])->toBe(2)
        ->and($v2['dtstart'])->toBe('2026-09-07T19:00:00')
        ->and($v2['rrule'])->toBe('FREQ=DAILY');
});

it('defaults dtstart to midnight when no start time is given', function() {
    expect(input(['startDate' => '2026-09-07', 'frequency' => 'DAILY'])['dtstart'])->toBe('2026-09-07T00:00:00');
});

it('builds an interval greater than one', function() {
    expect(input(['startDate' => '2026-09-07', 'frequency' => 'DAILY', 'interval' => '3'])['rrule'])
        ->toBe('FREQ=DAILY;INTERVAL=3');
});

it('builds a weekly rule from the selected weekdays', function() {
    $v2 = input(['startDate' => '2026-09-07', 'frequency' => 'WEEKLY', 'weekdays' => ['MO', 'FR', 'ZZ']]);

    expect($v2['rrule'])->toBe('FREQ=WEEKLY;BYDAY=MO,FR');
});

it('builds a plain weekly rule when no weekdays are selected', function() {
    expect(input(['startDate' => '2026-09-07', 'frequency' => 'WEEKLY'])['rrule'])->toBe('FREQ=WEEKLY');
});

it('builds a monthly positional rule from the ordinal and weekday', function() {
    $v2 = input(['startDate' => '2026-09-07', 'frequency' => 'MONTHLY', 'position' => '1', 'positionDay' => 'MO']);

    expect($v2['rrule'])->toBe('FREQ=MONTHLY;BYDAY=1MO');
});

it('builds a monthly last-weekday rule from a negative ordinal', function() {
    $v2 = input(['startDate' => '2026-09-07', 'frequency' => 'MONTHLY', 'position' => '-1', 'positionDay' => 'SA']);

    expect($v2['rrule'])->toBe('FREQ=MONTHLY;BYDAY=-1SA');
});

it('builds a plain monthly rule when no ordinal is chosen', function() {
    expect(input(['startDate' => '2026-09-07', 'frequency' => 'MONTHLY', 'position' => '', 'positionDay' => 'MO'])['rrule'])
        ->toBe('FREQ=MONTHLY');
});

it('appends a COUNT end condition', function() {
    expect(input(['startDate' => '2026-09-07', 'frequency' => 'DAILY', 'endCondition' => 'count', 'count' => '10'])['rrule'])
        ->toBe('FREQ=DAILY;COUNT=10');
});

it('appends an UNTIL end condition using the end time boundary', function() {
    $v2 = input([
        'startDate' => '2026-09-07',
        'endTime' => '21:00',
        'frequency' => 'WEEKLY',
        'weekdays' => ['MO'],
        'endCondition' => 'until',
        'until' => '2027-06-30',
    ]);

    expect($v2['endTime'])->toBe('21:00')
        ->and($v2['rrule'])->toBe('FREQ=WEEKLY;BYDAY=MO;UNTIL=20270630T210000Z');
});

it('ignores the end condition when never is chosen', function() {
    expect(input(['startDate' => '2026-09-07', 'frequency' => 'DAILY', 'endCondition' => 'never'])['rrule'])
        ->toBe('FREQ=DAILY');
});

it('round-trips a raw rrule verbatim in advanced mode', function() {
    $v2 = input([
        'startDate' => '2026-09-07',
        'mode' => 'advanced',
        'rrule' => 'FREQ=MONTHLY;BYSETPOS=1;BYDAY=MO,TU,WE,TH,FR',
        'frequency' => 'DAILY',
    ]);

    expect($v2['rrule'])->toBe('FREQ=MONTHLY;BYSETPOS=1;BYDAY=MO,TU,WE,TH,FR');
});

it('collects exclusion and extra dates, dropping blanks and duplicates', function() {
    $v2 = input([
        'startDate' => '2026-09-07',
        'frequency' => 'DAILY',
        'exdates' => ['2027-05-01', '', '2027-05-01', '2027-12-25'],
        'rdates' => ['2027-07-01'],
    ]);

    expect($v2['exdates'])->toBe(['2027-05-01', '2027-12-25'])
        ->and($v2['rdates'])->toBe(['2027-07-01']);
});

it('normalizes the holidays block, lowercasing the country', function() {
    $v2 = input([
        'startDate' => '2026-09-07',
        'frequency' => 'DAILY',
        'holidaysEnabled' => '1',
        'holidaysCountry' => 'BE',
        'holidaysRegion' => 'DE-BY',
    ]);

    expect($v2['holidays'])->toBe(['enabled' => true, 'country' => 'be', 'region' => 'DE-BY']);
});

it('carries the reminder value and nulls an empty period', function() {
    $with = input(['startDate' => '2026-09-07', 'frequency' => 'DAILY', 'reminderValue' => '2', 'reminderPeriod' => 'days']);
    $without = input(['startDate' => '2026-09-07', 'frequency' => 'DAILY', 'reminderValue' => '0', 'reminderPeriod' => '']);

    expect($with['reminder'])->toBe(['value' => 2, 'period' => 'days'])
        ->and($without['reminder'])->toBe(['value' => 0, 'period' => null]);
});

it('interprets the start date in the injected timezone', function() {
    expect(input(['startDate' => '2026-09-07', 'startTime' => '19:00', 'frequency' => 'DAILY'], 'Europe/Brussels')['timezone'])
        ->toBe('Europe/Brussels');
});

it('round-trips a hand-written WKST through the simple editor unchanged', function() {
    // WKST is UI-representable but not editable: rruleToInput exposes it for
    // the hidden passthrough input, and inputToV2 re-emits it verbatim.
    $rule = ValueNormalizer::rruleToInput('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO;WKST=SU');

    expect($rule['wkst'])->toBe('SU');

    $v2 = input([
        'startDate' => '2026-09-07',
        'frequency' => 'WEEKLY',
        'weekdays' => ['MO'],
        'interval' => 2,
        'wkst' => $rule['wkst'],
    ]);

    expect($v2['rrule'])->toBe('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO;WKST=SU');
});

it('ignores an invalid WKST passthrough value', function() {
    $v2 = input([
        'startDate' => '2026-09-07',
        'frequency' => 'DAILY',
        'wkst' => 'XX',
    ]);

    expect($v2['rrule'])->toBe('FREQ=DAILY');
});

it('exposes an empty wkst for rules without one', function() {
    expect(ValueNormalizer::rruleToInput('FREQ=DAILY')['wkst'])->toBe('');
});

// UI representability (isUiRepresentable)
// -------------------------------------------------------------------------

it('treats an empty rule as representable', function() {
    expect(ValueNormalizer::isUiRepresentable(null))->toBeTrue()
        ->and(ValueNormalizer::isUiRepresentable(''))->toBeTrue();
});

it('treats the curated subset as representable', function() {
    expect(ValueNormalizer::isUiRepresentable('FREQ=DAILY'))->toBeTrue()
        ->and(ValueNormalizer::isUiRepresentable('FREQ=DAILY;INTERVAL=3;COUNT=5'))->toBeTrue()
        ->and(ValueNormalizer::isUiRepresentable('FREQ=WEEKLY;BYDAY=MO,FR;UNTIL=20270630T235900Z'))->toBeTrue()
        ->and(ValueNormalizer::isUiRepresentable('FREQ=MONTHLY;BYDAY=1MO'))->toBeTrue()
        ->and(ValueNormalizer::isUiRepresentable('FREQ=MONTHLY;BYDAY=-1SA'))->toBeTrue();
});

it('flags a rule with an unsupported part as not representable', function() {
    expect(ValueNormalizer::isUiRepresentable('FREQ=MONTHLY;BYMONTHDAY=-1'))->toBeFalse()
        ->and(ValueNormalizer::isUiRepresentable('FREQ=MONTHLY;BYSETPOS=1;BYDAY=MO,TU'))->toBeFalse()
        ->and(ValueNormalizer::isUiRepresentable('FREQ=YEARLY;BYMONTH=3'))->toBeFalse()
        ->and(ValueNormalizer::isUiRepresentable('FREQ=WEEKLY;BYWEEKNO=1'))->toBeFalse();
});

it('flags an unsupported frequency as not representable', function() {
    expect(ValueNormalizer::isUiRepresentable('FREQ=HOURLY'))->toBeFalse()
        ->and(ValueNormalizer::isUiRepresentable('FREQ=SECONDLY'))->toBeFalse();
});

it('flags a misplaced or ambiguous BYDAY as not representable', function() {
    // Plain weekdays only make sense weekly; a positional token only monthly;
    // a single positional token at that.
    expect(ValueNormalizer::isUiRepresentable('FREQ=MONTHLY;BYDAY=MO'))->toBeFalse()
        ->and(ValueNormalizer::isUiRepresentable('FREQ=WEEKLY;BYDAY=1MO'))->toBeFalse()
        ->and(ValueNormalizer::isUiRepresentable('FREQ=MONTHLY;BYDAY=1MO,3MO'))->toBeFalse()
        ->and(ValueNormalizer::isUiRepresentable('FREQ=WEEKLY;BYDAY=XX'))->toBeFalse();
});
