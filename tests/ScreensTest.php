<?php
/**
 * Timeloop plugin for Craft CMS 5.x
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) CraftPulse
 */

// =========================================================================
// SCREENS API (VALUE LEVEL)
// =========================================================================
// Standalone coverage for the value-level "screens" surface on TimeloopModel
// (isActiveNow / isActiveAt / currentOccurrence / nextOccurrence /
// occurrences). No Craft app is booted: the model falls back to a fresh
// TimeloopService (which itself falls back to a fresh HolidaysService), so
// the holiday-aware read path is exercised end to end without a container.
// The now-relative getters are asserted only where deterministic (the empty
// value); the holiday-aware window semantics are pinned at the engine level
// in RecurrenceTest, which can evaluate at a fixed instant.

use craftpulse\timeloop\models\TimeloopModel;
use craftpulse\timeloop\models\ValueNormalizer;

// Helpers
// -------------------------------------------------------------------------

/**
 * Builds a Europe/Brussels date-time for assertions and arguments.
 */
function screenDate(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone('Europe/Brussels'));
}

/**
 * Builds a TimeloopModel from a v2 config, normalized in Europe/Brussels.
 */
function screenModel(array $config): TimeloopModel
{
    return new TimeloopModel(ValueNormalizer::normalize($config, new DateTimeZone('Europe/Brussels')));
}

/**
 * Builds the acceptance-case model: a weekly Monday class, Sept 2027 to June
 * 2028, 19:00 to 21:00 in Europe/Brussels, with the given holidays config.
 *
 * @param array{enabled: bool, country: ?string, region: ?string} $holidays
 */
function screenClass(array $holidays): TimeloopModel
{
    return screenModel([
        'version' => 2,
        'dtstart' => '2027-09-06T19:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;UNTIL=20280630T000000Z',
        'holidays' => $holidays,
        'endTime' => '21:00',
    ]);
}

/**
 * Maps a list of occurrences to `Y-m-d H:i` strings.
 */
function screenYmdHi(array $occurrences): array
{
    return array_map(fn(DateTimeInterface $d) => $d->format('Y-m-d H:i'), $occurrences);
}

// Acceptance case 3 — holiday-aware active state
// -------------------------------------------------------------------------

it('is not active on a public holiday that lands on an occurrence', function() {
    // 2028-05-01 (Dag van de Arbeid) is a Monday; the class is closed.
    $class = screenClass(['enabled' => true, 'country' => 'be', 'region' => null]);

    expect($class->isActiveAt(screenDate('2028-05-01 19:30:00')))->toBeFalse();
});

it('is active on a regular class evening', function() {
    $class = screenClass(['enabled' => true, 'country' => 'be', 'region' => null]);

    expect($class->isActiveAt(screenDate('2028-04-24 19:30:00')))->toBeTrue()
        ->and($class->isActiveAt(screenDate('2028-05-08 19:30:00')))->toBeTrue();
});

it('reports the first occurrence after a holiday as the following Monday', function() {
    $class = screenClass(['enabled' => true, 'country' => 'be', 'region' => null]);

    $after = $class->occurrences(screenDate('2028-05-01 00:00:00'), screenDate('2028-05-31 23:59:59'), 1);

    expect(screenYmdHi($after))->toBe(['2028-05-08 19:00']);
});

it('keeps the holiday occurrence active when the toggle is off', function() {
    $class = screenClass(['enabled' => false, 'country' => 'be', 'region' => null]);

    expect($class->isActiveAt(screenDate('2028-05-01 19:30:00')))->toBeTrue();
});

// isActiveAt window boundaries
// -------------------------------------------------------------------------

it('bounds the timed active window by endTime (exclusive)', function() {
    // Window is [19:00, 21:00): active at 20:59, closed at 21:00 and before 19:00.
    $class = screenClass(['enabled' => false, 'country' => null, 'region' => null]);

    expect($class->isActiveAt(screenDate('2027-09-06 19:00:00')))->toBeTrue()
        ->and($class->isActiveAt(screenDate('2027-09-06 20:59:00')))->toBeTrue()
        ->and($class->isActiveAt(screenDate('2027-09-06 21:00:00')))->toBeFalse()
        ->and($class->isActiveAt(screenDate('2027-09-06 18:59:00')))->toBeFalse();
});

it('spans the whole calendar day for an all-day occurrence', function() {
    $allDay = screenModel([
        'version' => 2,
        'dtstart' => '2027-09-06T00:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=DAILY;COUNT=10',
        'endTime' => null,
    ]);

    expect($allDay->isActiveAt(screenDate('2027-09-06 00:00:00')))->toBeTrue()
        ->and($allDay->isActiveAt(screenDate('2027-09-06 12:00:00')))->toBeTrue()
        ->and($allDay->isActiveAt(screenDate('2027-09-06 23:59:00')))->toBeTrue()
        ->and($allDay->isActiveAt(screenDate('2027-09-07 00:00:00')))->toBeTrue();
});

// occurrences(from, to, limit) bounds
// -------------------------------------------------------------------------

it('bounds occurrences inclusively at both ends', function() {
    $class = screenClass(['enabled' => false, 'country' => null, 'region' => null]);

    $dates = $class->occurrences(screenDate('2027-09-06 19:00:00'), screenDate('2027-09-27 19:00:00'));

    expect(screenYmdHi($dates))->toBe([
        '2027-09-06 19:00',
        '2027-09-13 19:00',
        '2027-09-20 19:00',
        '2027-09-27 19:00',
    ]);
});

it('caps occurrences by the limit', function() {
    $class = screenClass(['enabled' => false, 'country' => null, 'region' => null]);

    $dates = $class->occurrences(screenDate('2027-09-06 19:00:00'), screenDate('2027-12-31 19:00:00'), 2);

    expect(screenYmdHi($dates))->toBe([
        '2027-09-06 19:00',
        '2027-09-13 19:00',
    ]);
});

// Empty value
// -------------------------------------------------------------------------

it('is inert for a value that carries no rule', function() {
    $empty = screenModel(ValueNormalizer::emptyValue('Europe/Brussels'));

    expect($empty->occurrences())->toBe([])
        ->and($empty->getIsActiveNow())->toBeFalse()
        ->and($empty->getCurrentOccurrence())->toBeNull()
        ->and($empty->getNextOccurrence())->toBeNull();
});
