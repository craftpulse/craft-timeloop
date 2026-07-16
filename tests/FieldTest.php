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
