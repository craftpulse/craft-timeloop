<?php
/**
 * Timeloop plugin for Craft CMS 5.x
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) CraftPulse
 */

// =========================================================================
// FIELD: CP POST COERCION
// =========================================================================
// Standalone coverage for `TimeloopField::_coerceLegacyDates()`'s pure
// branches, invoked via reflection so no Craft app needs to boot to
// construct the field (its constructor and init() never touch Craft::$app).
//
// The method's one Craft-dependent branch -- converting a genuine date-picker
// POST array (`{date, time, locale, timezone}`) via
// `DateTimeHelper::toDateTime()` -- calls `Craft::$app->getFormattingLocale()`
// unconditionally and cannot be exercised without a booted Craft app; that
// branch is out of scope here and is instead covered indirectly by the
// existing HTTP/functional test suite once one exists. What *is* pure and
// tested below is the coercion method's dispatch logic: it must recognize a
// v2 value and a pre-coerced legacy value and leave both untouched, only
// reaching for `DateTimeHelper` when a key holds neither a string nor a
// `DateTimeInterface`.

use craftpulse\timeloop\fields\TimeloopField;

/**
 * Invokes the private `_coerceLegacyDates()` method via reflection.
 */
function coerceLegacyDates(array $value): array
{
    $field = new TimeloopField();
    $method = new ReflectionMethod(TimeloopField::class, '_coerceLegacyDates');

    return $method->invoke($field, $value);
}

it('passes a v2 value through untouched', function() {
    $v2 = [
        'version' => 2,
        'dtstart' => '2026-09-07T19:00:00',
        'timezone' => 'UTC',
        'rrule' => 'FREQ=DAILY',
    ];

    expect(coerceLegacyDates($v2))->toBe($v2);
});

it('leaves an already-ISO-string legacy date untouched', function() {
    $legacy = [
        'loopStartDate' => '2026-09-07T19:00:00+00:00',
        'loopEndDate' => '2027-06-30T20:00:00+00:00',
    ];

    expect(coerceLegacyDates($legacy))->toBe($legacy);
});

it('leaves an already-DateTimeInterface legacy date untouched', function() {
    $start = new DateTimeImmutable('2026-09-07T19:00:00+00:00');
    $legacy = ['loopStartDate' => $start];

    expect(coerceLegacyDates($legacy)['loopStartDate'])->toBe($start);
});

it('skips a legacy date key that is absent', function() {
    $legacy = ['loopReminderValue' => 2, 'loopReminderPeriod' => 'days'];

    expect(coerceLegacyDates($legacy))->toBe($legacy);
});

// =========================================================================
// FIELD: UI INPUT DATE COERCION
// =========================================================================
// `TimeloopField::coerceInputDates()` reduces the date/time-picker POST arrays
// to plain strings for the pure `ValueNormalizer::inputToV2()`. Only its pure
// branches (string / empty / absent values) are exercised here; the
// DateTimeHelper branch (a genuine picker array) needs a booted Craft app and
// is covered by the manual/functional gate, matching `_coerceLegacyDates()`.

it('leaves already-string UI input dates untouched, nulling empties', function() {
    $coerced = TimeloopField::coerceInputDates([
        'startDate' => '2026-09-07',
        'startTime' => '19:00',
        'endTime' => '',
        'until' => '2027-06-30',
        'frequency' => 'WEEKLY',
    ]);

    expect($coerced['startDate'])->toBe('2026-09-07')
        ->and($coerced['startTime'])->toBe('19:00')
        ->and($coerced['endTime'])->toBeNull()
        ->and($coerced['until'])->toBe('2027-06-30')
        ->and($coerced['frequency'])->toBe('WEEKLY');
});

it('nulls absent UI input date keys', function() {
    $coerced = TimeloopField::coerceInputDates(['frequency' => 'DAILY']);

    expect($coerced['startDate'])->toBeNull()
        ->and($coerced['startTime'])->toBeNull()
        ->and($coerced['endTime'])->toBeNull()
        ->and($coerced['until'])->toBeNull();
});
