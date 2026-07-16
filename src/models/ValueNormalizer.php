<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\models;

use craft\helpers\Json;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Legacy value normalizer.
 *
 * Upgrades any historical Timeloop field value (Craft 4.x, the 5.0.0 betas and
 * 5.0.0 stable) to the v2 storage shape in memory, and derives the legacy
 * {@see PeriodModel}/{@see TimeStringModel} views back out of a v2 `rrule` for
 * the backwards-compatibility shim.
 *
 * The class is intentionally pure: every method is static, no method reads
 * `Craft::$app`, and the target timezone is always injected. This lets the
 * content migration, the field's read-time normalization and the Pest suites
 * exercise the exact same mapping without booting a Craft application. The
 * field layer is responsible for coercing raw control-panel POST values
 * (Craft `DateTime`/date-field arrays) into ISO-8601 strings before handing
 * them here.
 *
 * ## Storage shapes across versions
 *
 * All three vintages (4.1.1, 5.0.0-beta.3 and 5.0.0) wrote the *same* JSON
 * structure to the content store, because every one serialized the field value
 * through `craft\base\Model::toArray()`, which renders `\DateTime` attributes as
 * UTC ISO-8601 strings:
 *
 * ```json
 * {
 *   "loopStartDate": "2026-09-07T18:00:00+00:00",
 *   "loopEndDate":   "2027-06-30T20:00:00+00:00",
 *   "loopStartTime": "2026-01-01T19:00:00+00:00",
 *   "loopEndTime":   "2026-01-01T21:00:00+00:00",
 *   "loopReminderValue": 2,
 *   "loopReminderPeriod": "days",
 *   "loopPeriod": {"frequency":"P1W","cycle":1,"days":["Monday"],"timestring":{"ordinal":"none","day":"none"}}
 * }
 * ```
 *
 * `days[]` are capitalized full names (`"Monday"`), `timestring.ordinal` and
 * `timestring.day` are lowercase (`"first"`, `"monday"`, or `"none"`). The only
 * behavioural difference between vintages lived in the *engine*, not the stored
 * shape; the one storage-visible bug (#62) is that 4.1.1 and beta.3 merged the
 * loop *start* time into `loopEndDate` instead of the end time. This normalizer
 * repairs that during mapping (see below).
 *
 * ## Month-end mapping (deliberate decision)
 *
 * The 5.0.0 engine expanded a monthly loop by clamping day overflow to the last
 * day of the target month: a start on Jan 31 produced Jan 31, Feb 28, Mar 31,
 * Apr 30, ... (verified by tracing `TimeloopService::_monthCorrection()` in
 * `git show 5.0.0`). A plain RFC RRULE (`FREQ=MONTHLY`) instead *skips* months
 * that lack the start day. To keep the shim's output identical to 5.0.0, a
 * monthly loop whose `dtstart` falls on the last day of its month is mapped to
 * `BYMONTHDAY=-1` (RFC "last day of month"), which reproduces the 5.0.0 series
 * exactly. A monthly loop whose start day is 1-28 is mapped to a plain
 * `FREQ=MONTHLY` (the day is derived from `dtstart` and exists in every month,
 * so 5.0.0 and RRULE agree).
 *
 * The one residual gap: a start on day 29 or 30 that is *not* the last day of
 * its own month (e.g. Jan 30) cannot be expressed as a single RRULE. 5.0.0
 * kept the day on long months but clamped to the last day on short months
 * (Jan 30, Feb 28, Mar 30, ...); RRULE can only skip (`BYMONTHDAY=30` omits
 * February) or always clamp (`BYMONTHDAY=-1` would wrongly give Jan 31). This
 * class maps that case to a plain `FREQ=MONTHLY` (skip), the closest expressible
 * behaviour, and the divergence is documented rather than silently shipped.
 *
 * ## Unknown-frequency default (deliberate divergence from 5.0.0)
 *
 * {@see parseRrule()} defaults an unrecognized `FREQ` to `DAILY`. The 5.0.0
 * engine's `_calculateInterval()` defaulted an unrecognized legacy frequency to
 * `yearly` instead. Both defaults are garbage-in/garbage-out cases: the legacy
 * `loopPeriod.frequency` is always one of `P1D`/`P1W`/`P1M`/`P1Y` when written
 * by the control panel, so this path is only reachable through a free-string
 * `FREQ` submitted via raw GraphQL mutation input. `DAILY` is kept as the safer
 * of the two garbage defaults (bounded daily expansion vs. a silent decade-plus
 * jump to `yearly`) rather than chasing exact 5.0.0 parity for a value no
 * legitimate caller can produce (pinned by a `NormalizerTest` case).
 *
 * ## #62 end-time repair
 *
 * When mapping `loopEndDate` to the RRULE `UNTIL`, the boundary time is taken
 * from `loopEndTime` (falling back to `23:59`), never from the time baked into
 * the stored `loopEndDate`. This mirrors the 5.0.0 `serializeValue()` intent and
 * transparently repairs the 4.1.1/beta.3 #62 bug for migrated values.
 *
 * @author CraftPulse
 * @since 5.1.0
 */
class ValueNormalizer
{
    // Constants
    // =========================================================================

    /**
     * @var int The current storage format version.
     */
    public const VERSION = 2;

    /**
     * @var string[] Map of legacy weekly day names to RFC 5545 two-letter codes.
     */
    private const DAY_TO_BYDAY = [
        'monday' => 'MO',
        'tuesday' => 'TU',
        'wednesday' => 'WE',
        'thursday' => 'TH',
        'friday' => 'FR',
        'saturday' => 'SA',
        'sunday' => 'SU',
    ];

    /**
     * @var string[] Map of RFC 5545 two-letter codes to capitalized legacy day names.
     */
    private const BYDAY_TO_DAY = [
        'MO' => 'Monday',
        'TU' => 'Tuesday',
        'WE' => 'Wednesday',
        'TH' => 'Thursday',
        'FR' => 'Friday',
        'SA' => 'Saturday',
        'SU' => 'Sunday',
    ];

    /**
     * @var int[] Map of legacy monthly ordinals to RFC 5545 positional prefixes.
     */
    private const ORDINAL_TO_POSITION = [
        'first' => 1,
        'second' => 2,
        'third' => 3,
        'fourth' => 4,
        'last' => -1,
    ];

    /**
     * @var string[] Map of RFC 5545 positional prefixes to legacy monthly ordinals.
     */
    private const POSITION_TO_ORDINAL = [
        1 => 'first',
        2 => 'second',
        3 => 'third',
        4 => 'fourth',
        -1 => 'last',
    ];

    /**
     * @var string[] Map of legacy ISO-8601 duration frequencies to RFC 5545 frequencies.
     */
    private const PERIOD_TO_FREQ = [
        'P1D' => 'DAILY',
        'P1W' => 'WEEKLY',
        'P1M' => 'MONTHLY',
        'P1Y' => 'YEARLY',
    ];

    /**
     * @var string[] Map of RFC 5545 frequencies to legacy ISO-8601 duration frequencies.
     */
    private const FREQ_TO_PERIOD = [
        'DAILY' => 'P1D',
        'WEEKLY' => 'P1W',
        'MONTHLY' => 'P1M',
        'YEARLY' => 'P1Y',
    ];

    // Static Methods
    // =========================================================================

    /**
     * Returns the canonical, empty v2 value.
     *
     * @param string $timezone The IANA timezone to stamp on the value.
     * @return array
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public static function emptyValue(string $timezone): array
    {
        return [
            'version' => self::VERSION,
            'dtstart' => null,
            'timezone' => $timezone,
            'rrule' => null,
            'exdates' => [],
            'rdates' => [],
            'holidays' => self::_defaultHolidays(),
            'endTime' => null,
            'reminder' => ['value' => 0, 'period' => null],
        ];
    }

    /**
     * Returns whether a stored value is a non-empty legacy value needing upgrade to v2.
     *
     * Pure predicate shared by the read-time field normalizer's callers and the
     * content migration ({@see \craftpulse\timeloop\migrations\m260716_000000_timeloop_v2_content}),
     * so the "does this need upgrading" decision lives in one place.
     *
     * @param mixed $value The raw decoded field value.
     * @return bool
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public static function needsUpgrade(mixed $value): bool
    {
        if (!is_array($value) || $value === []) {
            return false;
        }

        return (int)($value['version'] ?? 0) < self::VERSION;
    }

    /**
     * Normalizes any legacy or v2 field value to the v2 storage shape.
     *
     * The mapping is idempotent: a v2 value in returns the same v2 value out
     * (with any missing keys backfilled to their defaults).
     *
     * @param array $value The raw decoded field value (legacy or v2).
     * @param DateTimeZone $timezone The timezone dates are interpreted and stored in.
     * @return array The v2 value.
     * @throws \Exception if a stored date string cannot be parsed (via [[_toDate()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public static function normalize(array $value, DateTimeZone $timezone): array
    {
        if ((int)($value['version'] ?? 0) >= self::VERSION) {
            return self::_normalizeV2($value, $timezone);
        }

        return self::_upgradeLegacy($value, $timezone);
    }

    /**
     * Derives the legacy loop-period array from a v2 `rrule` string.
     *
     * Returns `null` when there is no rule, matching the 5.0.0 behaviour where a
     * value without a period had no expandable dates.
     *
     * @param ?string $rrule The v2 RRULE string.
     * @return ?array A `{frequency, cycle, days, timestring}` array, or null.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public static function rruleToLoopPeriod(?string $rrule): ?array
    {
        if ($rrule === null || $rrule === '') {
            return null;
        }

        $parts = self::parseRrule($rrule);
        $days = [];
        $timestring = ['ordinal' => 'none', 'day' => 'none'];

        foreach ($parts['byday'] as $byday) {
            if (preg_match('/^(-?\d+)([A-Z]{2})$/', $byday, $matches)) {
                $timestring = [
                    'ordinal' => self::POSITION_TO_ORDINAL[(int)$matches[1]] ?? 'none',
                    'day' => strtolower(self::BYDAY_TO_DAY[$matches[2]] ?? 'none'),
                ];

                continue;
            }

            if (isset(self::BYDAY_TO_DAY[$byday])) {
                $days[] = self::BYDAY_TO_DAY[$byday];
            }
        }

        return [
            'frequency' => self::FREQ_TO_PERIOD[$parts['freq']] ?? 'P1D',
            'cycle' => $parts['interval'],
            'days' => $days,
            'timestring' => $timestring,
        ];
    }

    /**
     * Parses an RRULE string into its component parts.
     *
     * String parsing keeps the `RRule\*` namespace out of everything but
     * {@see RecurrenceModel}, which is the only class allowed to touch it.
     *
     * @param string $rrule The RRULE string.
     * @return array{freq: string, interval: int, byday: string[], bymonthday: ?string, until: ?string}
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public static function parseRrule(string $rrule): array
    {
        $pairs = [];

        foreach (explode(';', $rrule) as $segment) {
            if (!str_contains($segment, '=')) {
                continue;
            }

            [$key, $val] = explode('=', $segment, 2);
            $pairs[strtoupper(trim($key))] = trim($val);
        }

        return [
            'freq' => $pairs['FREQ'] ?? 'DAILY',
            'interval' => max(1, (int)($pairs['INTERVAL'] ?? 1)),
            'byday' => isset($pairs['BYDAY']) && $pairs['BYDAY'] !== '' ? explode(',', $pairs['BYDAY']) : [],
            'bymonthday' => $pairs['BYMONTHDAY'] ?? null,
            'until' => $pairs['UNTIL'] ?? null,
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Backfills a v2 value's optional keys to their defaults (idempotent path).
     *
     * @param array $value
     * @param DateTimeZone $timezone
     * @return array
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _normalizeV2(array $value, DateTimeZone $timezone): array
    {
        $reminder = is_array($value['reminder'] ?? null) ? $value['reminder'] : [];
        $holidays = is_array($value['holidays'] ?? null) ? $value['holidays'] : [];

        return [
            'version' => self::VERSION,
            'dtstart' => $value['dtstart'] ?? null,
            'timezone' => $value['timezone'] ?? $timezone->getName(),
            'rrule' => $value['rrule'] ?? null,
            'exdates' => array_values((array)($value['exdates'] ?? [])),
            'rdates' => array_values((array)($value['rdates'] ?? [])),
            'holidays' => [
                'enabled' => (bool)($holidays['enabled'] ?? false),
                'country' => $holidays['country'] ?? null,
                'region' => $holidays['region'] ?? null,
            ],
            'endTime' => $value['endTime'] ?? null,
            'reminder' => [
                'value' => (int)($reminder['value'] ?? 0),
                'period' => ($reminder['period'] ?? null) ?: null,
            ],
        ];
    }

    /**
     * Upgrades a legacy value to the v2 shape.
     *
     * @param array $value
     * @param DateTimeZone $timezone
     * @return array
     * @throws \Exception if a stored date string cannot be parsed (via [[_toDate()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _upgradeLegacy(array $value, DateTimeZone $timezone): array
    {
        $start = self::_toDate($value['loopStartDate'] ?? null, $timezone);

        if ($start === null) {
            return self::emptyValue($timezone->getName());
        }

        $startTime = self::_toDate($value['loopStartTime'] ?? null, $timezone);

        if ($startTime !== null) {
            $start = $start->setTime((int)$startTime->format('H'), (int)$startTime->format('i'), 0);
        }

        $endTime = self::_toDate($value['loopEndTime'] ?? null, $timezone);
        $endTimeString = $endTime?->format('H:i');

        return [
            'version' => self::VERSION,
            'dtstart' => $start->format('Y-m-d\TH:i:s'),
            'timezone' => $timezone->getName(),
            'rrule' => self::_buildRrule($value, $start, $endTimeString, $timezone),
            'exdates' => [],
            'rdates' => [],
            'holidays' => self::_defaultHolidays(),
            'endTime' => $endTimeString,
            'reminder' => [
                'value' => (int)($value['loopReminderValue'] ?? 0),
                'period' => ($value['loopReminderPeriod'] ?? null) ?: null,
            ],
        ];
    }

    /**
     * Builds the RRULE string from a legacy value.
     *
     * @param array $value The legacy value.
     * @param DateTimeImmutable $start The resolved local start date-time.
     * @param ?string $endTime The resolved end time (`H:i`) or null.
     * @param DateTimeZone $timezone
     * @return ?string
     * @throws \Exception if `loopEndDate` cannot be parsed (via [[_toDate()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _buildRrule(array $value, DateTimeImmutable $start, ?string $endTime, DateTimeZone $timezone): ?string
    {
        $period = self::_period($value['loopPeriod'] ?? null);

        if ($period === null) {
            return null;
        }

        $freq = self::PERIOD_TO_FREQ[$period['frequency'] ?? 'P1D'] ?? 'DAILY';
        $parts = ["FREQ=$freq"];
        $cycle = max(1, (int)($period['cycle'] ?? 1));

        if ($cycle > 1) {
            $parts[] = "INTERVAL=$cycle";
        }

        $byday = self::_byday($freq, $period, $start);

        if ($byday !== null) {
            $parts[] = $byday;
        }

        $until = self::_until($value['loopEndDate'] ?? null, $endTime, $timezone);

        if ($until !== null) {
            $parts[] = "UNTIL=$until";
        }

        return implode(';', $parts);
    }

    /**
     * Returns the `BYDAY`/`BYMONTHDAY` segment for a legacy period, or null.
     *
     * @param string $freq The RFC frequency.
     * @param array $period The legacy period array.
     * @param DateTimeImmutable $start The resolved local start date-time.
     * @return ?string
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _byday(string $freq, array $period, DateTimeImmutable $start): ?string
    {
        if ($freq === 'WEEKLY') {
            $codes = [];

            foreach ((array)($period['days'] ?? []) as $day) {
                $code = self::DAY_TO_BYDAY[strtolower((string)$day)] ?? null;

                if ($code !== null) {
                    $codes[] = $code;
                }
            }

            return $codes === [] ? null : 'BYDAY=' . implode(',', $codes);
        }

        if ($freq !== 'MONTHLY') {
            return null;
        }

        $timestring = is_array($period['timestring'] ?? null) ? $period['timestring'] : [];
        $ordinal = strtolower((string)($timestring['ordinal'] ?? 'none'));
        $day = strtolower((string)($timestring['day'] ?? 'none'));

        if (isset(self::ORDINAL_TO_POSITION[$ordinal], self::DAY_TO_BYDAY[$day])) {
            return 'BYDAY=' . self::ORDINAL_TO_POSITION[$ordinal] . self::DAY_TO_BYDAY[$day];
        }

        // Month-end starts drifted to the last day of the month under 5.0.0;
        // BYMONTHDAY=-1 reproduces that. See the class docblock for the
        // day-29/30 residual gap.
        if ($start->format('d') === $start->format('t')) {
            return 'BYMONTHDAY=-1';
        }

        return null;
    }

    /**
     * Returns the RRULE `UNTIL` value (UTC, `Ymd\THis\Z`) for a legacy end date.
     *
     * @param mixed $loopEndDate The legacy end date.
     * @param ?string $endTime The resolved end time (`H:i`), used to repair #62.
     * @param DateTimeZone $timezone
     * @return ?string
     * @throws \Exception if `$loopEndDate` cannot be parsed (via [[_toDate()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _until(mixed $loopEndDate, ?string $endTime, DateTimeZone $timezone): ?string
    {
        $end = self::_toDate($loopEndDate, $timezone);

        if ($end === null) {
            return null;
        }

        [$hour, $minute] = $endTime !== null
            ? array_map('intval', explode(':', $endTime))
            : [23, 59];

        return $end
            ->setTime($hour, $minute, 0)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');
    }

    /**
     * Decodes a legacy loop-period value into an array, or null when absent.
     *
     * @param mixed $period An array, a JSON string, or null.
     * @return ?array
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _period(mixed $period): ?array
    {
        if (is_string($period) && $period !== '') {
            $period = Json::decodeIfJson($period);
        }

        if (!is_array($period) || $period === []) {
            return null;
        }

        return $period;
    }

    /**
     * Coerces a stored date value into an immutable date in the target timezone.
     *
     * @param mixed $value An ISO-8601 string, or null/empty.
     * @param DateTimeZone $timezone
     * @return ?DateTimeImmutable
     * @throws \Exception if the string is not a valid date-time.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _toDate(mixed $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($timezone);
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value))->setTimezone($timezone);
    }

    /**
     * Returns the default (disabled) holidays configuration.
     *
     * @return array{enabled: bool, country: ?string, region: ?string}
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _defaultHolidays(): array
    {
        return ['enabled' => false, 'country' => null, 'region' => null];
    }
}
