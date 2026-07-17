<?php
/**
 * Timeloop plugin for Craft CMS 5.x
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) CraftPulse
 */

// =========================================================================
// ICS EXPORT + PHASE 7 ENGINE SURFACE
// =========================================================================
// Standalone coverage for the ICS body builder and the recurrence-engine
// helpers it depends on. No Craft app is booted, so the ICS is generated
// without baked-in holidays (IcsService falls back to the value's own
// recurrence); the holiday-aware path is covered by the playground gate.

use craftpulse\timeloop\models\RecurrenceModel;
use craftpulse\timeloop\models\TimeloopModel;
use craftpulse\timeloop\services\IcsService;

// Helpers
// -------------------------------------------------------------------------

/**
 * Builds a normalized v2 field value model.
 */
function timeloopValue(array $overrides = []): TimeloopModel
{
    return new TimeloopModel(array_merge([
        'version' => 2,
        'dtstart' => '2027-09-06T19:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;UNTIL=20280630T000000Z',
        'exdates' => [],
        'rdates' => [],
        'holidays' => ['enabled' => false, 'country' => null, 'region' => null],
        'endTime' => '21:00',
        'reminder' => ['value' => 0, 'period' => null],
    ], $overrides));
}

/**
 * Generates the ICS body for a value through the standalone service.
 */
function ics(TimeloopModel $value, ?string $summary = null): string
{
    return (new IcsService())->calendarFor($value, $summary, 'test-uid@timeloop');
}

// RecurrenceModel::rfcString
// -------------------------------------------------------------------------

it('returns the RRULE value only from rfcString, delegating to the library', function() {
    $model = new RecurrenceModel([
        'dtstart' => '2027-09-06T19:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;UNTIL=20280630T000000Z',
    ]);

    $rrule = $model->rfcString();

    expect($rrule)->not->toContain('DTSTART')
        ->and($rrule)->toStartWith('FREQ=WEEKLY')
        ->and($rrule)->toContain('BYDAY=MO')
        ->and($rrule)->toContain('UNTIL=20280630T000000Z');
});

it('returns null from rfcString when there is no rule', function() {
    $model = new RecurrenceModel(['dtstart' => '2027-09-06T19:00:00', 'timezone' => 'UTC']);

    expect($model->rfcString())->toBeNull();
});

// RecurrenceModel::exclusionDates
// -------------------------------------------------------------------------

it('anchors exclusion dates to the occurrence time of day', function() {
    $model = new RecurrenceModel([
        'dtstart' => '2027-09-06T19:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
        'exdates' => ['2027-12-27'],
    ]);

    $exclusions = $model->exclusionDates();

    expect($exclusions)->toHaveCount(1)
        ->and($exclusions[0]->format('Y-m-d H:i'))->toBe('2027-12-27 19:00');
});

it('merges injected exclusions into exclusionDates', function() {
    $model = new RecurrenceModel([
        'dtstart' => '2027-09-06T19:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
        'exdates' => ['2027-12-27'],
    ]);
    $model->setExtraExclusions(['2027-12-25']);

    expect(ymd($model->exclusionDates()))->toBe(['2027-12-27', '2027-12-25']);
});

// IcsService::calendarFor
// -------------------------------------------------------------------------

it('builds a valid VCALENDAR with a recurring VEVENT', function() {
    $body = ics(timeloopValue(), 'Monday dance class');

    expect($body)->toContain('BEGIN:VCALENDAR')
        ->and($body)->toContain('END:VCALENDAR')
        ->and($body)->toContain('BEGIN:VEVENT')
        ->and($body)->toContain('BEGIN:VTIMEZONE')
        ->and($body)->toContain('SUMMARY:Monday dance class')
        ->and($body)->toContain('UID:test-uid@timeloop');
});

it('emits a timezone-correct DTSTART and DTEND', function() {
    $body = ics(timeloopValue());

    expect($body)->toContain('DTSTART;TZID=Europe/Brussels:20270906T190000')
        ->and($body)->toContain('DTEND;TZID=Europe/Brussels:20270906T210000');
});

it('emits the RRULE verbatim from the engine rfcString', function() {
    $value = timeloopValue();
    $expected = $value->getRecurrence()->rfcString();
    $body = ics($value);

    preg_match('/RRULE:(.+)/', $body, $matches);

    expect(trim($matches[1] ?? ''))->toBe($expected);
});

it('emits an EXDATE for each stored exclusion', function() {
    $body = ics(timeloopValue(['exdates' => ['2027-12-27']]));

    expect($body)->toContain('EXDATE')
        ->and($body)->toContain('20271227T190000');
});

it('builds a full-day event when no end time is set', function() {
    $body = ics(timeloopValue(['endTime' => null]));

    expect($body)->toContain('DTSTART;TZID=Europe/Brussels;VALUE=DATE:20270906')
        ->and($body)->not->toContain('DTEND;TZID=Europe/Brussels:20270906T21');
});

it('omits the RRULE for a value that carries no rule', function() {
    $body = ics(timeloopValue(['rrule' => null]));

    expect($body)->toContain('BEGIN:VEVENT')
        ->and($body)->not->toContain('RRULE:');
});

// #83: loopReminder resolver surface
// -------------------------------------------------------------------------

it('exposes the reminder period the loopReminder GQL resolver returns', function() {
    // The GraphQL `loopReminder` resolver returns `$source->loopReminderPeriod`.
    // Pre-5.1 the field had no resolver and always returned null; the value
    // model now derives the period from the v2 reminder config.
    $value = timeloopValue(['reminder' => ['value' => 2, 'period' => 'days']]);

    expect($value->loopReminderPeriod)->toBe('days')
        ->and($value->loopReminderValue)->toBe(2);
});

it('exposes a null reminder period when the value carries no reminder', function() {
    expect(timeloopValue()->loopReminderPeriod)->toBeNull();
});
