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

    /**
     * @var string[] The RFC 5545 frequencies the control-panel UI can produce and edit.
     */
    private const UI_FREQUENCIES = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    /**
     * @var string[] The RFC 5545 two-letter weekday codes.
     */
    private const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    /**
     * @var string[] RRULE parts the control-panel UI can render as editable controls.
     *
     * Any stored rule carrying a part outside this set (e.g. `BYSETPOS`,
     * `BYMONTH`, `BYMONTHDAY`, `BYWEEKNO`, `BYHOUR`) is not representable by the
     * simple editor: {@see isUiRepresentable()} returns false for it, and the
     * field renders the rule read-only while round-tripping it verbatim.
     */
    private const UI_RRULE_PARTS = ['FREQ', 'INTERVAL', 'BYDAY', 'UNTIL', 'COUNT', 'WKST'];

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
     * @return array{freq: string, interval: int, byday: string[], bymonthday: ?string, until: ?string, count: ?int, wkst: ?string}
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
            'count' => isset($pairs['COUNT']) && $pairs['COUNT'] !== '' ? (int)$pairs['COUNT'] : null,
            'wkst' => $pairs['WKST'] ?? null,
        ];
    }

    /**
     * Derives the control-panel input control state from a v2 `rrule` string.
     *
     * The inverse of the simple-mode branch of {@see inputToV2()}: it maps a
     * representable rule back onto the frequency / interval / weekday /
     * position / end-condition controls so the field can pre-fill them. It is
     * only meaningful for a rule {@see isUiRepresentable()} accepts; an empty
     * rule yields the editor's defaults.
     *
     * `wkst` is not editable in the UI; it is carried through a hidden input so
     * a hand-written `WKST` part survives a simple-mode edit unchanged.
     *
     * @param ?string $rrule The v2 RRULE string.
     * @return array{frequency: string, interval: int, weekdays: string[], position: string, positionDay: string, endCondition: string, count: ?int, wkst: string}
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public static function rruleToInput(?string $rrule): array
    {
        $default = [
            'frequency' => 'WEEKLY',
            'interval' => 1,
            'weekdays' => [],
            'position' => '',
            'positionDay' => '',
            'endCondition' => 'never',
            'count' => null,
            'wkst' => '',
        ];

        if ($rrule === null || $rrule === '') {
            return $default;
        }

        $parts = self::parseRrule($rrule);
        $weekdays = [];
        $position = '';
        $positionDay = '';

        foreach ($parts['byday'] as $token) {
            if (preg_match('/^(-?\d+)(MO|TU|WE|TH|FR|SA|SU)$/', $token, $matches) === 1) {
                $position = $matches[1];
                $positionDay = $matches[2];

                continue;
            }

            if (in_array($token, self::WEEKDAYS, true)) {
                $weekdays[] = $token;
            }
        }

        return [
            'frequency' => in_array($parts['freq'], self::UI_FREQUENCIES, true) ? $parts['freq'] : 'DAILY',
            'interval' => $parts['interval'],
            'weekdays' => $weekdays,
            'position' => $position,
            'positionDay' => $positionDay,
            'endCondition' => $parts['until'] !== null ? 'until' : ($parts['count'] !== null ? 'count' : 'never'),
            'count' => $parts['count'],
            'wkst' => in_array($parts['wkst'], self::WEEKDAYS, true) ? $parts['wkst'] : '',
        ];
    }

    /**
     * Normalizes the control-panel input POST subset into the v2 storage shape.
     *
     * The field layer coerces the date-picker POST arrays
     * (`startDate`/`startTime`/`endTime`/`until`) into plain strings before
     * handing them here, so this method stays pure (no `Craft::$app`, injected
     * timezone) and is exercised directly by the Pest suite. An empty
     * `startDate` yields the canonical empty value.
     *
     * In `simple` mode the RRULE is assembled from the curated subset
     * (`frequency`, `interval`, `weekdays`, `position`/`positionDay`,
     * `endCondition` + `until`/`count`). In `advanced` mode the posted `rrule`
     * is round-tripped verbatim, so a rule the editor cannot represent (see
     * {@see isUiRepresentable()}) survives an edit of its dates, holidays,
     * times or reminder untouched.
     *
     * @param array $input The coerced input POST subset.
     * @param DateTimeZone $timezone The timezone the value is stored and expanded in.
     * @return array The v2 value.
     * @throws \Exception if `startDate` cannot be parsed, or `until` cannot be parsed (via [[_until()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public static function inputToV2(array $input, DateTimeZone $timezone): array
    {
        $startDate = self::_string($input['startDate'] ?? null);

        if ($startDate === null) {
            return self::emptyValue($timezone->getName());
        }

        $start = new DateTimeImmutable($startDate, $timezone);
        $startTime = self::_time($input['startTime'] ?? null);

        if ($startTime !== null) {
            $start = $start->setTime((int)$startTime[0], (int)$startTime[1], 0);
        }

        $endTime = self::_time($input['endTime'] ?? null);
        $endTimeString = $endTime !== null ? sprintf('%02d:%02d', $endTime[0], $endTime[1]) : null;

        $mode = ($input['mode'] ?? 'simple') === 'advanced' ? 'advanced' : 'simple';
        $rawRrule = self::_string($input['rrule'] ?? null);
        $rrule = $mode === 'advanced' && $rawRrule !== null
            ? $rawRrule
            : self::_buildRruleFromInput($input, $endTimeString, $timezone);

        $country = self::_string($input['holidaysCountry'] ?? null);
        $region = self::_string($input['holidaysRegion'] ?? null);
        $reminderPeriod = self::_string($input['reminderPeriod'] ?? null);

        return [
            'version' => self::VERSION,
            'dtstart' => $start->format('Y-m-d\TH:i:s'),
            'timezone' => $timezone->getName(),
            'rrule' => $rrule,
            'exdates' => self::_dateList($input['exdates'] ?? []),
            'rdates' => self::_dateList($input['rdates'] ?? []),
            'holidays' => [
                'enabled' => (bool)($input['holidaysEnabled'] ?? false),
                'country' => $country !== null ? strtolower($country) : null,
                'region' => $region,
            ],
            'endTime' => $endTimeString,
            'reminder' => [
                'value' => (int)($input['reminderValue'] ?? 0),
                'period' => $reminderPeriod,
            ],
        ];
    }

    /**
     * Returns whether an RRULE can be rendered as editable controls by the CP UI.
     *
     * The simple editor understands `FREQ` (daily/weekly/monthly/yearly),
     * `INTERVAL`, `BYDAY` (plain weekdays for a weekly rule, or a single
     * positional token such as `1MO`/`-1SA` for a monthly rule), and the
     * `UNTIL`/`COUNT` end conditions. Anything else (`BYSETPOS`, `BYMONTH`,
     * `BYMONTHDAY`, `BYWEEKNO`, `BYHOUR`, a positional `BYDAY` on a non-monthly
     * rule, multiple positional tokens, an unsupported frequency, and so on) is
     * not representable: the field renders it read-only and round-trips it
     * verbatim rather than silently dropping the advanced parts.
     *
     * An empty rule is trivially representable (a fresh value the editor fills in).
     *
     * @param ?string $rrule The RRULE string without a `DTSTART`.
     * @return bool
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public static function isUiRepresentable(?string $rrule): bool
    {
        if ($rrule === null || $rrule === '') {
            return true;
        }

        $pairs = [];

        foreach (explode(';', $rrule) as $segment) {
            if (!str_contains($segment, '=')) {
                continue;
            }

            [$key, $val] = explode('=', $segment, 2);
            $pairs[strtoupper(trim($key))] = trim($val);
        }

        if (array_diff(array_keys($pairs), self::UI_RRULE_PARTS) !== []) {
            return false;
        }

        $freq = strtoupper($pairs['FREQ'] ?? '');

        if (!in_array($freq, self::UI_FREQUENCIES, true)) {
            return false;
        }

        if (!isset($pairs['BYDAY']) || $pairs['BYDAY'] === '') {
            return true;
        }

        return self::_isUiRepresentableByday($pairs['BYDAY'], $freq);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns whether a `BYDAY` value is representable for the given frequency.
     *
     * Plain weekday tokens (`MO,FR`) are representable only for a weekly rule; a
     * single positional token (`1MO`, `-1SA`) only for a monthly rule. Any mix,
     * multiple positional tokens, or an unrecognized token is not representable.
     *
     * @param string $byday The raw `BYDAY` value.
     * @param string $freq The uppercase RFC frequency.
     * @return bool
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _isUiRepresentableByday(string $byday, string $freq): bool
    {
        $tokens = explode(',', $byday);
        $positional = false;
        $plain = false;

        foreach ($tokens as $token) {
            if (in_array($token, self::WEEKDAYS, true)) {
                $plain = true;

                continue;
            }

            if (preg_match('/^-?\d+(MO|TU|WE|TH|FR|SA|SU)$/', $token) === 1) {
                $positional = true;

                continue;
            }

            return false;
        }

        if ($positional) {
            return !$plain && count($tokens) === 1 && $freq === 'MONTHLY';
        }

        return $freq === 'WEEKLY';
    }

    /**
     * Builds the RRULE string from the curated control-panel input subset.
     *
     * @param array $input The input POST subset.
     * @param ?string $endTime The resolved end time (`H:i`), used for the `UNTIL` boundary.
     * @param DateTimeZone $timezone
     * @return string
     * @throws \Exception if `until` cannot be parsed (via [[_inputUntil()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _buildRruleFromInput(array $input, ?string $endTime, DateTimeZone $timezone): string
    {
        $freq = strtoupper(self::_string($input['frequency'] ?? '') ?? '');

        if (!in_array($freq, self::UI_FREQUENCIES, true)) {
            $freq = 'DAILY';
        }

        $parts = ["FREQ=$freq"];
        $interval = max(1, (int)($input['interval'] ?? 1));

        if ($interval > 1) {
            $parts[] = "INTERVAL=$interval";
        }

        $byday = self::_bydayFromInput($freq, $input);

        if ($byday !== null) {
            $parts[] = "BYDAY=$byday";
        }

        $endCondition = self::_string($input['endCondition'] ?? null);

        if ($endCondition === 'count') {
            $parts[] = 'COUNT=' . max(1, (int)($input['count'] ?? 1));
        } elseif ($endCondition === 'until') {
            $until = self::_inputUntil(self::_string($input['until'] ?? null), $endTime, $timezone);

            if ($until !== null) {
                $parts[] = "UNTIL=$until";
            }
        }

        // WKST is not editable in the UI; the hidden passthrough input carries
        // a hand-written week-start unchanged through a simple-mode edit.
        $wkst = strtoupper(self::_string($input['wkst'] ?? '') ?? '');

        if (in_array($wkst, self::WEEKDAYS, true)) {
            $parts[] = "WKST=$wkst";
        }

        return implode(';', $parts);
    }

    /**
     * Returns the `BYDAY` value for the curated input subset, or null.
     *
     * @param string $freq The uppercase RFC frequency.
     * @param array $input The input POST subset.
     * @return ?string
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _bydayFromInput(string $freq, array $input): ?string
    {
        if ($freq === 'WEEKLY') {
            $codes = array_values(array_filter(
                (array)($input['weekdays'] ?? []),
                static fn(mixed $code): bool => in_array($code, self::WEEKDAYS, true),
            ));

            return $codes === [] ? null : implode(',', $codes);
        }

        if ($freq !== 'MONTHLY') {
            return null;
        }

        $position = (int)($input['position'] ?? 0);
        $day = self::_string($input['positionDay'] ?? null);

        if (!isset(self::POSITION_TO_ORDINAL[$position]) || $day === null || !in_array($day, self::WEEKDAYS, true)) {
            return null;
        }

        return $position . $day;
    }

    /**
     * Returns the RRULE `UNTIL` value (UTC, `Ymd\THis\Z`) for a UI end date.
     *
     * Unlike {@see _until()} (the legacy path, which parses offset-carrying ISO
     * strings), the UI posts a bare `Y-m-d` date. It is interpreted directly in
     * the value's timezone so the wall-clock day is preserved before converting
     * to UTC for the boundary.
     *
     * @param ?string $date The bare `Y-m-d` end date, or null.
     * @param ?string $endTime The resolved end time (`H:i`), or null (defaults to `23:59`).
     * @param DateTimeZone $timezone
     * @return ?string
     * @throws \Exception if `$date` is not a valid date string.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _inputUntil(?string $date, ?string $endTime, DateTimeZone $timezone): ?string
    {
        if ($date === null) {
            return null;
        }

        [$hour, $minute] = $endTime !== null
            ? array_map('intval', explode(':', $endTime))
            : [23, 59];

        return (new DateTimeImmutable($date, $timezone))
            ->setTime($hour, $minute, 0)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');
    }

    /**
     * Normalizes a scalar input value into a non-empty trimmed string, or null.
     *
     * @param mixed $value
     * @return ?string
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _string(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * Parses an `H:i` (or ISO) time string into an `[hour, minute]` pair, or null.
     *
     * @param mixed $value
     * @return ?int[] A two-element `[hour, minute]` list, or null.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _time(mixed $value): ?array
    {
        $value = self::_string($value);

        if ($value === null || preg_match('/(\d{1,2}):(\d{2})/', $value, $matches) !== 1) {
            return null;
        }

        return [(int)$matches[1], (int)$matches[2]];
    }

    /**
     * Filters a list of date values into unique non-empty trimmed strings.
     *
     * @param mixed $value An array of date strings, or a scalar.
     * @return string[]
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _dateList(mixed $value): array
    {
        $dates = [];

        foreach ((array)$value as $date) {
            $date = self::_string($date);

            if ($date !== null) {
                $dates[$date] = true;
            }
        }

        return array_keys($dates);
    }

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
