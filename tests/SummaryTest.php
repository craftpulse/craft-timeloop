<?php
/**
 * Timeloop plugin for Craft CMS 5.x
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) CraftPulse
 */

// =========================================================================
// LOCALIZED SUMMARY + TRANSLATION FILES
// =========================================================================
// Standalone coverage for TimeloopModel::getSummary() and a sanity check
// that every plugin translation file exposes the same key set. No Craft app
// is booted, so the summary's default locale resolution degrades to null and
// the library renders its English catalogue.

use craftpulse\timeloop\models\TimeloopModel;

// Helpers
// -------------------------------------------------------------------------

/**
 * Builds a field-value model from the v2 storage shape.
 */
function fieldValue(array $config): TimeloopModel
{
    return new TimeloopModel($config);
}

/**
 * Loads a plugin translation catalogue by locale.
 */
function catalogue(string $locale): array
{
    return require dirname(__DIR__) . "/src/translations/$locale/timeloop.php";
}

// getSummary()
// -------------------------------------------------------------------------

it('renders an English summary for a weekly rule', function() {
    $model = fieldValue([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=5',
    ]);

    expect($model->getSummary('en'))->toContain('weekly')
        ->and($model->getSummary('en'))->toContain('Monday');
});

it('renders a Dutch summary when the locale is passed explicitly', function() {
    $model = fieldValue([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=5',
    ]);

    expect($model->getSummary('nl'))->toContain('wekelijks')
        ->and($model->getSummary('nl'))->not->toBe($model->getSummary('en'));
});

it('falls back to English when no locale is given outside a Craft app', function() {
    // Standalone the current-language lookup degrades to null, so the default
    // renders the library's English catalogue rather than throwing.
    $model = fieldValue([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=5',
    ]);

    expect($model->getSummary())->toContain('weekly')
        ->and($model->getSummary())->toBe($model->getSummary('en'));
});

it('returns null when the value carries no rule', function() {
    $model = fieldValue([
        'dtstart' => '2026-01-05T09:00:00',
        'timezone' => 'Europe/Brussels',
        'rrule' => null,
    ]);

    expect($model->getSummary())->toBeNull()
        ->and($model->getSummary('nl'))->toBeNull();
});

// Translation catalogues
// -------------------------------------------------------------------------

it('exposes a catalogue array for every shipped locale', function(string $locale) {
    expect(catalogue($locale))->toBeArray()->not->toBeEmpty();
})->with(['en', 'nl', 'fr', 'de']);

it('keeps an identical key set across every locale', function(string $locale) {
    $reference = array_keys(catalogue('en'));
    $keys = array_keys(catalogue($locale));

    sort($reference);
    sort($keys);

    expect($keys)->toBe($reference);
})->with(['nl', 'fr', 'de']);

it('leaves no translation value blank', function(string $locale) {
    foreach (catalogue($locale) as $key => $translation) {
        expect($translation)->toBeString()->not->toBe('', "Empty translation for \"$key\" in $locale.");
    }
})->with(['en', 'nl', 'fr', 'de']);
