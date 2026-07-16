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
