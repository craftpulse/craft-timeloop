<?php
/**
 * Timeloop plugin for Craft CMS 5.x
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) CraftPulse
 */

// =========================================================================
// PUBLIC HOLIDAYS
// =========================================================================
// Standalone coverage for the holiday exclusion source and its read-time
// merge into the recurrence engine. No Craft app is booted: the service's
// core resolution needs only spatie + Carbon, and the country-defaulting
// path is exercised by injecting an explicit locale. The read-time merge is
// driven through a directly-instantiated TimeloopService, mirroring ShimTest.

use craftpulse\timeloop\models\TimeloopModel;
use craftpulse\timeloop\models\ValueNormalizer;
use craftpulse\timeloop\services\HolidaysService;
use craftpulse\timeloop\services\TimeloopService;

// Helpers
// -------------------------------------------------------------------------

/**
 * Builds a Europe/Brussels date-time for assertions and arguments.
 */
function holidayDate(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone('Europe/Brussels'));
}

/**
 * Builds the acceptance-case model: a weekly Monday class, Sept 2027 to June
 * 2028, in Europe/Brussels, running 19:00 to 21:00, with the given holidays
 * configuration.
 *
 * @param array{enabled: bool, country: ?string, region: ?string} $holidays
 */
function danceClass(array $holidays, array $exdates = []): TimeloopModel
{
    return new TimeloopModel(ValueNormalizer::normalize([
        'version' => 2,
        'dtstart' => '2027-09-06T19:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;UNTIL=20280630T000000Z',
        'exdates' => $exdates,
        'holidays' => $holidays,
        'endTime' => '21:00',
    ], new DateTimeZone('Europe/Brussels')));
}

/**
 * Maps a getLoop() result to `Y-m-d` strings.
 */
function loopYmd(?array $dates): array
{
    return array_map(fn(DateTimeInterface $d) => $d->format('Y-m-d'), $dates ?? []);
}

// Core resolution
// -------------------------------------------------------------------------

it('resolves a Belgian public holiday for a single year', function() {
    $dates = (new HolidaysService())->exclusionDates('be', null, 2028, 2028);

    expect($dates)->toContain('2028-05-01') // Dag van de Arbeid (a Monday in 2028)
        ->and($dates)->toContain('2028-01-01')
        ->and($dates)->toContain('2028-12-25');
});

it('spans a year boundary, picking up both years of holidays', function() {
    $dates = (new HolidaysService())->exclusionDates('be', null, 2027, 2028);

    expect($dates)->toContain('2027-11-01') // Allerheiligen 2027
        ->and($dates)->toContain('2027-12-25')
        ->and($dates)->toContain('2028-01-01')
        ->and($dates)->toContain('2028-05-01');
});

it('reaches a region-only holiday through the region override', function() {
    $service = new HolidaysService();

    // Fronleichnam (Corpus Christi, Easter + 60 = 2027-05-27) is a Bavarian
    // regional holiday, absent from the German national set.
    $regional = $service->exclusionDates('de', 'DE-BY', 2027, 2027);
    $national = $service->exclusionDates('de', null, 2027, 2027);

    expect($regional)->toContain('2027-05-27')
        ->and($national)->not->toContain('2027-05-27');
});

it('degrades to no exclusions for an unknown country without throwing', function() {
    $dates = (new HolidaysService())->exclusionDates('zz', null, 2028, 2028);

    expect($dates)->toBe([]);
});

it('degrades to no exclusions for an unknown region without throwing', function() {
    $dates = (new HolidaysService())->exclusionDates('de', 'DE-ZZ', 2028, 2028);

    // 2.x rejects the region (caught -> empty); 1.x ignores it and falls back
    // to the national set. Either way, resolution never throws.
    expect($dates)->toBeArray();
});

it('contributes no exclusions for years outside spatie range', function() {
    $dates = (new HolidaysService())->exclusionDates('be', null, 2040, 2050);

    expect($dates)->toBe([]);
});

// Country defaulting
// -------------------------------------------------------------------------

it('derives a country code from a locale region segment', function() {
    $service = new HolidaysService();

    expect($service->defaultCountryFromLocale('nl-BE'))->toBe('be')
        ->and($service->defaultCountryFromLocale('fr-FR'))->toBe('fr')
        ->and($service->defaultCountryFromLocale('en-US'))->toBe('us');
});

it('returns null for a locale without a region segment', function() {
    expect((new HolidaysService())->defaultCountryFromLocale('en'))->toBeNull();
});

it('resolves the country chain: explicit, then field default, then locale', function() {
    $service = new HolidaysService();

    expect($service->resolveCountry('be', 'nl', 'fr-FR'))->toBe('be')
        ->and($service->resolveCountry(null, 'nl', 'fr-FR'))->toBe('nl')
        ->and($service->resolveCountry(null, null, 'fr-FR'))->toBe('fr')
        ->and($service->resolveCountry(null, null, 'en'))->toBeNull();
});

// Memoization
// -------------------------------------------------------------------------

it('resolves each year through spatie at most once', function() {
    $spy = new class extends HolidaysService {
        public int $resolveCalls = 0;

        protected function _resolveYear(string $country, ?string $region, int $year): array
        {
            $this->resolveCalls++;

            return parent::_resolveYear($country, $region, $year);
        }
    };

    $spy->exclusionDates('be', null, 2027, 2028);
    $spy->exclusionDates('be', null, 2027, 2028);
    $spy->exclusionDates('be', null, 2028, 2028);

    // Two distinct years resolved, then served from the memo thereafter.
    expect($spy->resolveCalls)->toBe(2);
});

// Read-time merge
// -------------------------------------------------------------------------

it('excludes a public holiday that lands on an occurrence', function() {
    $dates = loopYmd((new TimeloopService())->getLoop(
        danceClass(['enabled' => true, 'country' => 'be', 'region' => null]),
        limit: 0,
        futureDates: false,
    ));

    expect($dates)->toContain('2028-04-24')
        ->and($dates)->toContain('2028-05-08')
        ->and($dates)->not->toContain('2028-05-01');
});

it('keeps a holiday occurrence when the toggle is off', function() {
    $dates = loopYmd((new TimeloopService())->getLoop(
        danceClass(['enabled' => false, 'country' => 'be', 'region' => null]),
        limit: 0,
        futureDates: false,
    ));

    expect($dates)->toContain('2028-05-01');
});

it('excludes a public holiday from the field-level settings alone', function() {
    // The value carries no holidays of its own; the field's stamped
    // enable/country settings drive the exclusion (the 5.1.0 chain: value-level
    // GraphQL override, then field settings, then disabled).
    $model = danceClass(['enabled' => false, 'country' => null, 'region' => null]);
    $model->holidayEnabledDefault = true;
    $model->holidayCountryDefault = 'be';

    $dates = loopYmd((new TimeloopService())->getLoop($model, limit: 0, futureDates: false));

    expect($dates)->toContain('2028-04-24')
        ->and($dates)->toContain('2028-05-08')
        ->and($dates)->not->toContain('2028-05-01');
});

it('reaches a field-level region-only holiday through the stamped region', function() {
    // Fronleichnam (2027-05-27) is a Bavarian regional holiday; the field-level
    // country + region alone must reach it.
    $model = new TimeloopModel(ValueNormalizer::normalize([
        'version' => 2,
        'dtstart' => '2027-05-01T09:00:00',
        'timezone' => 'Europe/Berlin',
        'rrule' => 'FREQ=DAILY;UNTIL=20270601T000000Z',
        'holidays' => ['enabled' => false, 'country' => null, 'region' => null],
    ], new DateTimeZone('Europe/Berlin')));
    $model->holidayEnabledDefault = true;
    $model->holidayCountryDefault = 'de';
    $model->holidayRegionDefault = 'DE-BY';

    $dates = loopYmd((new TimeloopService())->getLoop($model, limit: 0, futureDates: false));

    expect($dates)->not->toContain('2027-05-27');
});

it('reports no next occurrence on a holiday and skips to the following week', function() {
    $service = new TimeloopService();
    $recurrence = $service->recurrenceFor(
        danceClass(['enabled' => true, 'country' => 'be', 'region' => null]),
    );

    $next = $service->nextOccurrence($recurrence, holidayDate('2028-04-24 19:00:00'));

    expect($next?->format('Y-m-d'))->toBe('2028-05-08')
        ->and($service->activeAt($recurrence, holidayDate('2028-05-01 19:00:00')))->toBeFalse()
        ->and($service->occursAt($recurrence, holidayDate('2028-05-01 19:00:00')))->toBeFalse();
});

it('never writes resolved holidays into the stored exdates', function() {
    $model = danceClass(['enabled' => true, 'country' => 'be', 'region' => null], ['2027-12-24']);

    // Trigger a full read-time expansion with holidays injected.
    (new TimeloopService())->getLoop($model, limit: 0, futureDates: false);

    expect($model->exdates)->toBe(['2027-12-24'])
        ->and($model->toV2Array()['exdates'])->toBe(['2027-12-24']);
});
