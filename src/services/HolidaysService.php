<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\services;

use Carbon\CarbonImmutable;
use Craft;
use craft\base\Component;
use Spatie\Holidays\Countries\Country;
use Spatie\Holidays\Holidays;
use Throwable;

/**
 * Public-holidays exclusion source.
 *
 * Adapts `spatie/holidays` into a list of plain `Y-m-d` date strings that the
 * recurrence engine can subtract from a series via
 * {@see \craftpulse\timeloop\models\RecurrenceModel::setExtraExclusions()}.
 * Holidays are resolved at read time, per year, and are never persisted into a
 * value's stored `exdates`: a September-to-June weekly class keeps excluding
 * next year's public holidays across a year boundary without the entry being
 * re-saved.
 *
 * ## Both-majors support (`spatie/holidays: ^1.24 || ^2.0`)
 *
 * The plugin stays on `php: ^8.2`, so 1.x is the only major that runs in the
 * local container (2.x requires PHP 8.4). Every spatie symbol this class touches
 * is present in both majors, and the only shape that differs between them is
 * normalized defensively:
 *
 * - `Holidays::for(Country|string $country, ?int $year)` — called with two
 *   positional arguments. 2.x adds trailing optional `$locale`/`$region`
 *   parameters that are not used here, so the call is signature-compatible with
 *   both.
 * - `Holidays->get()` — called with no arguments (defaults to the configured
 *   country and year in both majors). The element shape differs: 1.x yields
 *   `array{name: string, date: CarbonImmutable}` entries, 2.x yields
 *   `Spatie\Holidays\Holiday` value objects with public `name`/`date`. A `(array)`
 *   cast normalizes both to an array carrying a `date` key (see [[_holidayDate()]]).
 * - `Country::find(string): ?Country` — called with one argument (2.x adds an
 *   optional region argument). Returns `null` for an unknown country in both.
 * - `Country::make(...)` — the variadic `new static(...func_get_args())` factory
 *   is present in both majors. It is invoked through `call_user_func()` so the
 *   region argument does not trip static analysis against 1.x's zero-parameter
 *   signature. On a country that supports regions the argument constructs the
 *   regional variant; on one that does not, the extra argument is ignored (1.x)
 *   or validated and rejected (2.x, caught below).
 *
 * Actual 2.x execution is deferred to a CI matrix at release; only 1.x is
 * exercised locally.
 *
 * ## Purity seam
 *
 * [[exclusionDates()]] is pure with respect to Craft: it needs only spatie and
 * Carbon, so the Pest suite exercises it without booting a Craft application
 * (the same discipline as {@see \craftpulse\timeloop\models\ValueNormalizer}).
 * The actual per-year spatie call is isolated in the protected [[_resolveYear()]]
 * seam so a test double can count resolutions and prove memoization. The one
 * Craft-coupled path, deriving a default country from the current site's locale,
 * is confined to [[_siteLocale()]] and is injectable via the `$locale` argument
 * of [[defaultCountryFromLocale()]].
 *
 * @author CraftPulse
 * @since 5.1.0
 */
class HolidaysService extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var int The earliest year spatie can calculate (Easter-anchored holidays rely on PHP's `easter_days()`).
     */
    public const MIN_YEAR = 1970;

    /**
     * @var int The latest year spatie can calculate.
     */
    public const MAX_YEAR = 2037;

    // Private Properties
    // =========================================================================

    /**
     * @var array<string, string[]> Memoized `Y-m-d` exclusion dates, keyed by `country|region|year`.
     */
    private array $_memo = [];

    /**
     * @var array<string, bool> Deduplication set for one-time warnings, keyed by message.
     */
    private array $_warned = [];

    // Public Methods
    // =========================================================================

    /**
     * Returns the public-holiday dates for a country and year span as `Y-m-d` strings.
     *
     * The result is memoized per `(country, region, year)`, so repeatedly
     * expanding the same value (or expanding several values sharing a country)
     * resolves each year through spatie at most once. The span is clamped to the
     * range spatie can calculate ([[MIN_YEAR]]..[[MAX_YEAR]]); years outside it
     * contribute no exclusions. An unknown country or region degrades to an empty
     * result with a one-time warning and never throws into the render path.
     *
     * @param string $country The ISO country code (case-insensitive), e.g. `be`.
     * @param ?string $region The country-specific region code, e.g. `DE-BY`, or null.
     * @param int $yearFrom The first year to resolve (inclusive).
     * @param int $yearTo The last year to resolve (inclusive).
     * @return string[] Unique `Y-m-d` holiday dates across the span.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function exclusionDates(string $country, ?string $region, int $yearFrom, int $yearTo): array
    {
        $yearFrom = max($yearFrom, self::MIN_YEAR);
        $yearTo = min($yearTo, self::MAX_YEAR);

        if ($yearTo < $yearFrom) {
            return [];
        }

        $dates = [];

        for ($year = $yearFrom; $year <= $yearTo; $year++) {
            $key = strtolower($country) . '|' . ($region ?? '') . '|' . $year;

            if (!array_key_exists($key, $this->_memo)) {
                $this->_memo[$key] = $this->_resolveYear($country, $region, $year);
            }

            $dates = [...$dates, ...$this->_memo[$key]];
        }

        return array_values(array_unique($dates));
    }

    /**
     * Resolves the effective holiday country for a value.
     *
     * The resolution order is: the value's explicit country, then the field's
     * `defaultHolidaysCountry` setting, then a country derived from the current
     * site's locale (see [[defaultCountryFromLocale()]]). When none resolves,
     * holidays are effectively disabled for the value (null is returned).
     *
     * @param ?string $valueCountry The country stored on the field value, or null.
     * @param ?string $fieldDefault The field's default country setting, or null.
     * @param ?string $locale An explicit locale to derive from (injected in tests); null reads the site.
     * @return ?string The lowercased country code, or null when none resolves.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function resolveCountry(?string $valueCountry, ?string $fieldDefault, ?string $locale = null): ?string
    {
        $country = $valueCountry ?: $fieldDefault ?: null;

        if ($country !== null) {
            return strtolower($country);
        }

        return $this->defaultCountryFromLocale($locale);
    }

    /**
     * Derives a default country code from a locale, e.g. `nl-BE` becomes `be`.
     *
     * When no locale is given the current site's language is used. A locale that
     * carries no region segment (e.g. `en`) cannot map to a country: it yields
     * null with a one-time warning.
     *
     * @param ?string $locale The locale to derive from, or null to read the current site.
     * @return ?string The lowercased country code, or null when none can be derived.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function defaultCountryFromLocale(?string $locale = null): ?string
    {
        $locale ??= $this->_siteLocale();

        if ($locale === null || $locale === '') {
            return null;
        }

        $position = strrpos($locale, '-');

        if ($position === false) {
            $this->_warn(sprintf('Timeloop: cannot derive a holiday country from locale "%s".', $locale));

            return null;
        }

        $country = strtolower(substr($locale, $position + 1));

        return $country !== '' ? $country : null;
    }

    // Protected Methods
    // =========================================================================

    /**
     * Resolves a single year's public-holiday dates through spatie.
     *
     * Isolated from [[exclusionDates()]] as the resolution seam: it is where the
     * spatie call lives (so a test double can count invocations to prove
     * memoization) and where every failure is contained. Years outside spatie's
     * calculable range, unknown countries and invalid regions all degrade to an
     * empty result with a one-time warning; nothing propagates to the caller.
     *
     * @param string $country The ISO country code.
     * @param ?string $region The region code, or null.
     * @param int $year The year to resolve.
     * @return string[] The year's `Y-m-d` holiday dates.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    protected function _resolveYear(string $country, ?string $region, int $year): array
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            return [];
        }

        try {
            $resolved = Country::find($country);

            if ($resolved === null) {
                $this->_warn(sprintf('Timeloop: unsupported holiday country "%s".', $country));

                return [];
            }

            if ($region !== null && $region !== '') {
                // `Country::make()` is variadic (`new static(...func_get_args())`)
                // in both majors; call_user_func() keeps the region argument out
                // of static analysis against 1.x's zero-parameter signature.
                $made = call_user_func([$resolved::class, 'make'], $region);

                if (!$made instanceof Country) {
                    return [];
                }

                $resolved = $made;
            }

            return array_map(
                fn(mixed $holiday): string => $this->_holidayDate($holiday),
                Holidays::for($resolved, $year)->get(),
            );
        } catch (Throwable $e) {
            $this->_warn(sprintf(
                'Timeloop: could not resolve holidays for "%s"%s: %s',
                $country,
                $region !== null && $region !== '' ? " ($region)" : '',
                $e->getMessage(),
            ));

            return [];
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Extracts an `Y-m-d` date string from a spatie holiday entry, across both majors.
     *
     * 1.x yields `array{name, date}` entries and 2.x yields `Holiday` value
     * objects; a `(array)` cast normalizes both to an array carrying a `date`
     * key holding a Carbon date.
     *
     * @param mixed $holiday A spatie holiday entry (1.x array or 2.x value object).
     * @return string The holiday date formatted `Y-m-d`.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _holidayDate(mixed $holiday): string
    {
        $holiday = (array)$holiday;
        /** @var CarbonImmutable $date */
        $date = $holiday['date'];

        return $date->format('Y-m-d');
    }

    /**
     * Returns the current site's language, or null when no Craft app is booted.
     *
     * The sole Craft-coupled path in this service. Wrapped so a non-booted unit
     * context (the standalone Pest suite) degrades to null instead of erroring;
     * callers inject an explicit locale to exercise the derivation purely.
     *
     * @return ?string The site language, or null.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _siteLocale(): ?string
    {
        try {
            return Craft::$app->getSites()->getCurrentSite()->language;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Logs a warning at most once per distinct message.
     *
     * Logging is best-effort: in a non-booted unit context the logger may be
     * unavailable, so a logging failure is swallowed and must never break holiday
     * resolution or the render path.
     *
     * @param string $message The message to log.
     * @return void
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _warn(string $message): void
    {
        if (isset($this->_warned[$message])) {
            return;
        }

        $this->_warned[$message] = true;

        try {
            Craft::warning($message, __METHOD__);
        } catch (Throwable) {
            // Best-effort; see the method docblock.
        }
    }
}
