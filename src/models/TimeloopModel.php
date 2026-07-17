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

use Craft;
use craft\base\Model;
use craftpulse\timeloop\services\TimeloopService;
use craftpulse\timeloop\Timeloop;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Timeloop field value model.
 *
 * As of 5.1.0 the field value is stored in the v2 shape (a local `dtstart`, an
 * IANA `timezone`, an `rrule`, static `exdates`/`rdates`, a `holidays` object,
 * an `endTime` and a `reminder`) and every recurrence expansion is delegated to
 * the {@see RecurrenceModel} engine.
 *
 * The full pre-5.1 public surface is preserved as a backwards-compatibility
 * shim. The legacy `loopStartDate`/`loopEndDate`/`loopStartTime`/`loopEndTime`/
 * `loopPeriod`/`loopReminderValue`/`loopReminderPeriod` values remain real
 * public properties (so `entry.field.loopStartDate` keeps returning a
 * `\DateTime` and the control-panel form keeps round-tripping) but are now
 * *derived* from the v2 data in [[init()]]; the v2 properties are the source of
 * truth. `getDates()`/`getUpcoming()`/`getPeriod()`/`getTimeString()`/
 * `getReminder()` and the `getLoop*()` getters keep their pre-5.1 signatures.
 * {@see PeriodModel}/{@see TimeStringModel} are parsed back out of the `rrule`
 * by {@see ValueNormalizer}.
 *
 * The derived `loopStart*`/`loopEnd*` `\DateTime` values are rebuilt on the
 * `dtstart` date, so their time-of-day (and therefore `getLoopStartTime()` /
 * `getLoopEndTime()`) matches 5.0.0 exactly; the arbitrary reference *date* the
 * old time picker stored is not reproduced, which only affects the seldom-used
 * `getLoopStart()`/`getLoopEnd()` `\DateTime` date component, never their time.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class TimeloopModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var int The storage format version.
     */
    public int $version = ValueNormalizer::VERSION;

    /**
     * @var ?string The local start date (and optional time), e.g. `2026-09-07T19:00:00`.
     */
    public ?string $dtstart = null;

    /**
     * @var string The IANA timezone the recurrence is stored and expanded in.
     */
    public string $timezone = 'UTC';

    /**
     * @var ?string The RRULE string without a `DTSTART`.
     */
    public ?string $rrule = null;

    /**
     * @var string[] Static exclusion dates removed from the set.
     */
    public array $exdates = [];

    /**
     * @var string[] Extra one-off dates added to the set.
     */
    public array $rdates = [];

    /**
     * @var array{enabled: bool, country: ?string, region: ?string} The holiday exclusion configuration.
     */
    public array $holidays = ['enabled' => false, 'country' => null, 'region' => null];

    /**
     * @var ?string The wall-clock end time each occurrence runs until, formatted `H:i`. Null means all-day.
     */
    public ?string $endTime = null;

    /**
     * @var array{value: int, period: ?string} The reminder configuration.
     */
    public array $reminder = ['value' => 0, 'period' => null];

    /**
     * @var bool Whether the owning field enables holidays, stamped at read time. Never persisted.
     *
     * Carries the owning field's `enableHolidays` setting into the read-time
     * holiday resolution (see {@see \craftpulse\timeloop\services\TimeloopService::recurrenceFor()}).
     * Populated by {@see \craftpulse\timeloop\fields\TimeloopField::normalizeValue()}
     * and deliberately excluded from [[toV2Array()]], so a field-level setting
     * never leaks into a value's stored JSON.
     */
    public bool $holidayEnabledDefault = false;

    /**
     * @var ?string The field's holiday country, stamped at read time. Never persisted.
     *
     * Carries the owning field's `holidaysCountry` setting into the read-time
     * holiday resolution (see
     * {@see \craftpulse\timeloop\services\HolidaysService::resolveCountry()}).
     * It is populated by {@see \craftpulse\timeloop\fields\TimeloopField::normalizeValue()}
     * and deliberately excluded from [[toV2Array()]], so a field-level setting
     * never leaks into a value's stored JSON.
     */
    public ?string $holidayCountryDefault = null;

    /**
     * @var ?string The field's holiday region, stamped at read time. Never persisted.
     *
     * Carries the owning field's `holidaysRegion` setting into the read-time
     * holiday resolution (see {@see \craftpulse\timeloop\services\TimeloopService::recurrenceFor()}).
     * Populated by {@see \craftpulse\timeloop\fields\TimeloopField::normalizeValue()}
     * and deliberately excluded from [[toV2Array()]], so a field-level setting
     * never leaks into a value's stored JSON.
     */
    public ?string $holidayRegionDefault = null;

    /**
     * @var ?DateTime The date the loop starts (derived; backwards-compatibility).
     */
    public ?DateTime $loopStartDate = null;

    /**
     * @var ?DateTime The date the loop ends (derived; backwards-compatibility).
     */
    public ?DateTime $loopEndDate = null;

    /**
     * @var ?DateTime The time of day each occurrence starts (derived; backwards-compatibility).
     */
    public ?DateTime $loopStartTime = null;

    /**
     * @var ?DateTime The time of day each occurrence ends (derived; backwards-compatibility).
     */
    public ?DateTime $loopEndTime = null;

    /**
     * @var ?string The reminder period unit, e.g. `days` (derived; backwards-compatibility).
     */
    public ?string $loopReminderPeriod = null;

    /**
     * @var ?int The reminder period value (derived; backwards-compatibility).
     */
    public ?int $loopReminderValue = null;

    /**
     * @var ?array The loop period configuration (derived; backwards-compatibility).
     */
    public ?array $loopPeriod = null;

    // Private Properties
    // =========================================================================

    /**
     * @var ?RecurrenceModel Memoized recurrence engine.
     */
    private ?RecurrenceModel $_recurrence = null;

    /**
     * @var ?array Memoized upcoming occurrences.
     */
    private ?array $_upcomingDates = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Derives the legacy backwards-compatibility properties from the v2 data.
     *
     * A malformed rule `UNTIL` degrades [[loopEndDate]] to null rather than
     * throwing (see the inline guard); only the stored timezone and `dtstart`
     * itself can still throw.
     *
     * @throws \Exception if the stored timezone or `dtstart` cannot be parsed.
     */
    public function init(): void
    {
        parent::init();

        $this->loopPeriod = ValueNormalizer::rruleToLoopPeriod($this->rrule);
        $this->loopReminderPeriod = $this->reminder['period'] ?? null;
        $this->loopReminderValue = (int)$this->reminder['value'];

        if ($this->dtstart === null) {
            return;
        }

        $timezone = new DateTimeZone($this->timezone);
        $this->loopStartDate = new DateTime($this->dtstart, $timezone);
        $this->loopStartTime = new DateTime($this->dtstart, $timezone);

        if ($this->endTime !== null) {
            [$hour, $minute] = array_map('intval', explode(':', $this->endTime));
            $this->loopEndTime = (new DateTime($this->dtstart, $timezone))->setTime($hour, $minute, 0);
        }

        $until = $this->rrule !== null ? ValueNormalizer::parseRrule($this->rrule)['until'] : null;

        if ($until !== null) {
            try {
                $this->loopEndDate = (new DateTime($until, new DateTimeZone('UTC')))->setTimezone($timezone);
            } catch (Throwable) {
                // A syntactically invalid UNTIL (e.g. a raw GraphQL mutation
                // posting garbage into `rrule`, see
                // {@see \craftpulse\timeloop\fields\TimeloopField::getElementValidationRules()})
                // must not crash construction: content normalization runs on
                // every `getFieldValue()` call, so throwing here would take
                // down the control panel edit screen, the element index and
                // any front-end template rendering the element, not just the
                // one read that actually needs the date. The recurrence
                // engine still reports the rule as invalid when it is
                // actually expanded (see {@see RecurrenceModel}); this only
                // defers that failure to a point where it can be surfaced as
                // a validation error instead of a fatal one.
                $this->loopEndDate = null;
            }
        }
    }

    /**
     * Returns the recurrence engine for this value, or null when there is no rule.
     *
     * @return ?RecurrenceModel
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function getRecurrence(): ?RecurrenceModel
    {
        if ($this->dtstart === null || $this->rrule === null || $this->rrule === '') {
            return null;
        }

        return $this->_recurrence ??= new RecurrenceModel([
            'dtstart' => $this->dtstart,
            'timezone' => $this->timezone,
            'rrule' => $this->rrule,
            'exdates' => $this->exdates,
            'rdates' => $this->rdates,
            'endTime' => $this->endTime,
        ]);
    }

    /**
     * Returns a localized, human-readable summary of the recurrence rule.
     *
     * Delegates to {@see RecurrenceModel::summary()}, which wraps the library's
     * `humanReadable()` (18 locales, including en, nl, fr and de) and falls back
     * to English for a locale its catalogue lacks. The summary describes the
     * RRULE only (frequency, interval, by-day, end condition); the holiday
     * exclusions are resolved per-year at read time and are deliberately not
     * part of the wording. Returns null when the value carries no rule.
     *
     * When no locale is given it resolves to the current site's language, so
     * `entry.field.summary` renders in the language of the page it appears on.
     * The resolution degrades to null (and therefore to the library's English
     * fallback) outside a booted Craft application, e.g. in the standalone test
     * suite.
     *
     * @param ?string $locale The locale to render in (e.g. `nl`, `fr-FR`); null uses the current site language.
     * @return ?string
     * @throws \InvalidArgumentException if the rule cannot be parsed, or the locale is malformed
     * and the `intl` extension is unavailable (see {@see RecurrenceModel::summary()}).
     * @throws \Exception if the stored `dtstart` or `timezone` cannot be parsed into a date-time
     * (see {@see RecurrenceModel::summary()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function getSummary(?string $locale = null): ?string
    {
        return $this->getRecurrence()?->summary($locale ?? $this->_currentLanguage());
    }

    // Screens API
    // =========================================================================

    /**
     * Returns whether an occurrence is in progress right now.
     *
     * Convenience getter (Twig `entry.field.isActiveNow`) around
     * [[isActiveAt()]] evaluated at the current instant in the value's timezone.
     *
     * @return bool
     * @throws \Exception if the recurrence cannot be expanded, or `now` cannot be
     * constructed in the stored timezone (see [[isActiveAt()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function getIsActiveNow(): bool
    {
        return $this->isActiveAt($this->_now());
    }

    /**
     * Returns whether an occurrence is in progress at the given date-time.
     *
     * Holiday-aware: the recurrence is resolved through
     * {@see TimeloopService::recurrenceFor()}, so a holiday landing on an
     * occurrence is never active. Cost is O(occurrences since the series start)
     * per call (documented on {@see RecurrenceModel::activeAt()}); fine at the
     * value level, not for looping over many entries (use the occurrence index
     * and the query behavior for that).
     *
     * @param DateTimeInterface $dateTime
     * @return bool
     * @throws \Exception if the recurrence cannot be expanded (see {@see TimeloopService::activeAt()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function isActiveAt(DateTimeInterface $dateTime): bool
    {
        $service = $this->_timeloop();
        $recurrence = $service->recurrenceFor($this);

        return $recurrence !== null && $service->activeAt($recurrence, $dateTime);
    }

    /**
     * Returns the start of the occurrence in progress right now, or null.
     *
     * Holiday-aware (see [[isActiveAt()]]). Twig: `entry.field.currentOccurrence`.
     *
     * @return ?DateTimeImmutable
     * @throws \Exception if the recurrence cannot be expanded, or `now` cannot be
     * constructed in the stored timezone (see {@see TimeloopService::currentOccurrence()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function getCurrentOccurrence(): ?DateTimeImmutable
    {
        $service = $this->_timeloop();
        $recurrence = $service->recurrenceFor($this);

        return $recurrence !== null ? $service->currentOccurrence($recurrence, $this->_now()) : null;
    }

    /**
     * Returns the first occurrence after now, or null.
     *
     * The engine-backed, holiday-aware surface of the legacy `upcoming` getter:
     * a holiday landing on the next scheduled date is skipped to the one after.
     * Twig: `entry.field.nextOccurrence`.
     *
     * @return ?DateTimeImmutable
     * @throws \Exception if the recurrence cannot be expanded (see {@see TimeloopService::nextOccurrence()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function getNextOccurrence(): ?DateTimeImmutable
    {
        $service = $this->_timeloop();
        $recurrence = $service->recurrenceFor($this);

        return $recurrence !== null ? $service->nextOccurrence($recurrence, $this->_now()) : null;
    }

    /**
     * Returns the occurrences of the value, bounded and holiday-aware.
     *
     * `from` defaults to the series start, `to` defaults to the
     * {@see TimeloopService::DEFAULT_HORIZON} horizon (so an infinite rule stays
     * bounded); pass `limit` to cap the result. Both boundaries are inclusive.
     * When neither `from` nor `to` is given the engine's own bounds apply (a
     * finite rule expands fully, an infinite rule is capped at `limit`).
     *
     * An infinite rule with no positive `limit` is always capped at
     * {@see RecurrenceModel::DEFAULT_LIMIT}, even when an explicit `from`/`to`
     * is given. {@see RecurrenceModel::occurrences()} already applies this cap
     * on its own "no range" path, but {@see RecurrenceModel::occurrencesBetween()}
     * has no cap of its own: a bounded `to` is assumed to already bound the
     * work. That assumption doesn't hold for a caller-supplied `to`, most
     * notably the `occurrences(rangeEnd: ...)` GraphQL field on a public
     * schema: `rangeEnd: "9999-12-31"` against an infinite daily rule and no
     * `limit` would otherwise force a multi-millennium expansion per request.
     * The guard lives here, once, so every caller of this model (GraphQL,
     * Twig, PHP) is protected, not just the GraphQL resolver.
     *
     * @param ?DateTimeInterface $from The lower boundary (inclusive), or null for the series start.
     * @param ?DateTimeInterface $to The upper boundary (inclusive), or null for the default horizon.
     * @param ?int $limit Maximum number of occurrences, or null for no cap.
     * @return DateTimeImmutable[]
     * @throws \InvalidArgumentException if the rule cannot be parsed (see {@see RecurrenceModel::isInfinite()}).
     * @throws \Exception if the recurrence cannot be expanded (see {@see TimeloopService::occurrencesBetween()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function occurrences(?DateTimeInterface $from = null, ?DateTimeInterface $to = null, ?int $limit = null): array
    {
        $service = $this->_timeloop();
        $recurrence = $service->recurrenceFor($this);

        if ($recurrence === null) {
            return [];
        }

        if (($limit === null || $limit <= 0) && $recurrence->isInfinite()) {
            $limit = RecurrenceModel::DEFAULT_LIMIT;
        }

        if ($from === null && $to === null) {
            return $service->occurrences($recurrence, $limit);
        }

        $timezone = new DateTimeZone($this->timezone);
        $from ??= new DateTimeImmutable((string)$this->dtstart, $timezone);
        $to ??= (new DateTimeImmutable('now', $timezone))->modify(TimeloopService::DEFAULT_HORIZON);

        return $service->occurrencesBetween($recurrence, $from, $to, $limit);
    }

    /**
     * Returns the canonical v2 value for storage.
     *
     * @return array
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function toV2Array(): array
    {
        return [
            'version' => ValueNormalizer::VERSION,
            'dtstart' => $this->dtstart,
            'timezone' => $this->timezone,
            'rrule' => $this->rrule,
            'exdates' => array_values($this->exdates),
            'rdates' => array_values($this->rdates),
            'holidays' => [
                'enabled' => (bool)($this->holidays['enabled'] ?? false),
                'country' => $this->holidays['country'] ?? null,
                'region' => $this->holidays['region'] ?? null,
            ],
            'endTime' => $this->endTime,
            'reminder' => [
                'value' => (int)($this->reminder['value'] ?? 0),
                'period' => ($this->reminder['period'] ?? null) ?: null,
            ],
        ];
    }

    /**
     * Returns whether the value carries no recurrence.
     *
     * @return bool
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function isEmpty(): bool
    {
        return $this->dtstart === null;
    }

    // Backwards-compatibility shim
    // =========================================================================

    /**
     * Returns the loop start time formatted as `H:i`, or null.
     *
     * @return ?string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getLoopStartTime(): ?string
    {
        return $this->loopStartTime?->format('H:i');
    }

    /**
     * Returns the loop end time formatted as `H:i`, or null.
     *
     * @return ?string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getLoopEndTime(): ?string
    {
        return $this->endTime;
    }

    /**
     * Returns the loop start time as a DateTime object.
     *
     * @return ?DateTime
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getLoopStart(): ?DateTime
    {
        return $this->loopStartTime;
    }

    /**
     * Returns the loop end time as a DateTime object.
     *
     * @return ?DateTime
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getLoopEnd(): ?DateTime
    {
        return $this->loopEndTime;
    }

    /**
     * Returns the loop period configuration as a model.
     *
     * @return ?PeriodModel
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getPeriod(): ?PeriodModel
    {
        if ($this->loopPeriod === null) {
            return null;
        }

        return new PeriodModel($this->loopPeriod);
    }

    /**
     * Returns the monthly timestring configuration as a model.
     *
     * @return ?TimeStringModel
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getTimeString(): ?TimeStringModel
    {
        if ($this->loopPeriod === null || empty($this->loopPeriod['timestring'])) {
            return null;
        }

        return new TimeStringModel($this->loopPeriod['timestring']);
    }

    /**
     * Returns the reminder date for the first upcoming occurrence.
     *
     * @return ?DateTime
     * @throws \Exception if the recurrence cannot be expanded (see {@see TimeloopService::getReminder()}).
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getReminder(): ?DateTime
    {
        return Timeloop::$plugin->timeloop->getReminder($this);
    }

    /**
     * Returns the computed recurrence dates.
     *
     * @param int $limit
     * @param bool $futureDates
     * @return ?array
     * @throws \Exception if the recurrence cannot be expanded (see {@see TimeloopService::getLoop()}).
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getDates(int $limit = 0, bool $futureDates = true): ?array
    {
        return Timeloop::$plugin->timeloop->getLoop($this, $limit, $futureDates);
    }

    /**
     * Returns the first upcoming occurrence.
     *
     * @return ?DateTime
     * @throws \Exception if the recurrence cannot be expanded (see {@see TimeloopService::getLoop()}).
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getUpcoming(): ?DateTime
    {
        return $this->_getUpcomingDates()[0] ?? null;
    }

    /**
     * Returns the second upcoming occurrence.
     *
     * @return ?DateTime
     * @throws \Exception if the recurrence cannot be expanded (see {@see TimeloopService::getLoop()}).
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getNextUpcoming(): ?DateTime
    {
        return $this->_getUpcomingDates()[1] ?? null;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['dtstart', 'timezone', 'rrule', 'endTime'], 'safe'];
        $rules[] = [['exdates', 'rdates', 'holidays', 'reminder', 'loopPeriod'], 'safe'];
        $rules[] = [['holidayEnabledDefault', 'holidayCountryDefault', 'holidayRegionDefault'], 'safe'];
        $rules[] = [['loopStartDate', 'loopEndDate', 'loopStartTime', 'loopEndTime'], 'safe'];
        $rules[] = [['version', 'loopReminderValue'], 'integer'];

        return $rules;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the current site's language, or null when no Craft app is booted.
     *
     * Wrapped so a non-booted unit context (the standalone Pest suite) degrades
     * to null instead of erroring, at which point the summary falls back to the
     * library's English catalogue. Mirrors the guarded pattern in
     * {@see \craftpulse\timeloop\services\HolidaysService}.
     *
     * @return ?string The site language, or null.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _currentLanguage(): ?string
    {
        try {
            return Craft::$app->getSites()->getCurrentSite()->language;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Memoizes and returns the next two upcoming occurrences.
     *
     * @return array
     * @throws \Exception if the recurrence cannot be expanded (see {@see TimeloopService::getLoop()}).
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _getUpcomingDates(): array
    {
        return $this->_upcomingDates ??= Timeloop::$plugin->timeloop->getLoop($this, 2) ?? [];
    }

    /**
     * Returns the current instant in the value's stored timezone.
     *
     * @return DateTimeImmutable
     * @throws \Exception if the stored timezone cannot be parsed.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone($this->timezone));
    }

    /**
     * Returns the Timeloop service.
     *
     * Prefers the plugin's registered singleton and falls back to a fresh
     * instance when no plugin is booted, so the value-level screens API stays
     * usable from the standalone Pest suite (the same guarded pattern as
     * {@see TimeloopService::recurrenceFor()}'s holiday lookup).
     *
     * @return TimeloopService
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _timeloop(): TimeloopService
    {
        try {
            $plugin = Timeloop::getInstance();

            if ($plugin !== null && $plugin->has('timeloop')) {
                return $plugin->getTimeloop();
            }
        } catch (Throwable) {
            // No booted plugin (e.g. the standalone unit context); fall through.
        }

        return new TimeloopService();
    }
}
