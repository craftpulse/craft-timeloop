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

use craft\base\Model;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RRule\RRule;
use RRule\RSet;

/**
 * Recurrence engine wrapper.
 *
 * Wraps `rlanvin/php-rrule`'s {@see RSet} and is the single place in the code
 * base where the `RRule\*` namespace may appear. Everything else (services,
 * fields, GraphQL, templates) talks to this model, never to the library.
 *
 * The model is built from the v2 storage shape (a local `dtstart` string, an
 * IANA `timezone`, an `rrule` string without `DTSTART`, static `exdates` and
 * `rdates`, an optional `endTime` and an injectable list of extra exclusion
 * dates). The extra-exclusion hook exists for the holiday service added in a
 * later phase; nothing in this class resolves holidays itself.
 *
 * Expansion is timezone correct: `dtstart` is interpreted in the stored
 * timezone, and occurrences are returned in that same timezone with a constant
 * wall-clock time across daylight-saving transitions (the library rebuilds each
 * occurrence from the `dtstart` timezone and re-applies the time of day). The
 * one exception is a wall-clock time that falls inside a spring-forward gap
 * (e.g. a `02:30` occurrence on the day local clocks jump from 02:00 to 03:00):
 * PHP's `DateTime` normalizes that invalid local time forward to the
 * corresponding post-transition instant (`03:30` in that example) rather than
 * erroring, and the library carries that normalization straight through.
 *
 * [[dtstart]], [[timezone]], [[rrule]], [[exdates]], [[rdates]] and [[endTime]]
 * are construct-once: the recurrence set is memoized on first expansion, and
 * mutating any of them afterwards is unsupported (the change would silently
 * have no effect while [[$_rset]] stays cached). [[extraExclusions]] is the one
 * property that supports post-construction mutation, via [[setExtraExclusions()]],
 * which explicitly resets the memoized set; this is the hook the Phase 3
 * holiday service uses to inject newly resolved exclusion dates without
 * rebuilding the model.
 *
 * @author CraftPulse
 * @since 5.1.0
 */
class RecurrenceModel extends Model
{
    // Constants
    // =========================================================================

    /**
     * @var int Fallback cap applied when expanding an infinite rule without an explicit limit.
     *
     * Only guards infinite rules (no `COUNT` or `UNTIL`): a finite rule always
     * expands fully when no limit is given, regardless of how many occurrences
     * it produces.
     */
    public const DEFAULT_LIMIT = 100;

    // Public Properties
    // =========================================================================

    /**
     * @var ?string The local start date (and optional time), e.g. `2026-09-07T19:00:00` or `2026-09-07`.
     */
    public ?string $dtstart = null;

    /**
     * @var string The IANA timezone the recurrence is stored and expanded in.
     */
    public string $timezone = 'UTC';

    /**
     * @var ?string The RRULE string without a `DTSTART`, e.g. `FREQ=WEEKLY;BYDAY=MO;UNTIL=20270630T000000Z`.
     */
    public ?string $rrule = null;

    /**
     * @var string[] Static exclusion dates (date or date-time strings) removed from the set.
     */
    public array $exdates = [];

    /**
     * @var string[] Extra one-off dates (date or date-time strings) added to the set.
     *
     * Rdates are anchored to the [[dtstart]] time of day (see [[_atOccurrenceTime()]]):
     * an rdate cannot carry a time of its own that differs from the rest of the
     * series. This is relevant to the future GraphQL raw-input contract, which
     * must not imply per-rdate time overrides.
     */
    public array $rdates = [];

    /**
     * @var ?string The wall-clock end time each occurrence runs until, formatted `H:i`. Null means all-day.
     */
    public ?string $endTime = null;

    // Private Properties
    // =========================================================================

    /**
     * @var string[] Injected exclusion dates resolved elsewhere (e.g. holidays). Merged with [[exdates]].
     */
    private array $_extraExclusions = [];

    /**
     * @var ?RSet Memoized recurrence set.
     */
    private ?RSet $_rset = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns whether the recurrence has no end condition (no `COUNT` or `UNTIL`).
     *
     * @return bool
     * @throws \InvalidArgumentException if the rule cannot be parsed.
     * @throws \Exception if [[dtstart]] or [[timezone]] cannot be parsed into a date-time, or an
     * exclusion/extra date cannot be parsed (see [[_rset()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function isInfinite(): bool
    {
        return $this->_rset()->isInfinite();
    }

    /**
     * Returns whether occurrences are all-day (no [[endTime]] is set).
     *
     * @return bool
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function isAllDay(): bool
    {
        return $this->endTime === null;
    }

    /**
     * Returns a localized, human-readable summary of the recurrence rule.
     *
     * Wraps the library's `humanReadable()` (which ships 18 locales, including
     * en, nl, fr and de). The locale is passed straight through; an unknown or
     * partially-supported locale falls back to English rather than throwing.
     * The summary describes the RRULE only (frequency, interval, by-day,
     * end condition) and deliberately ignores the injected holiday exclusions,
     * which are resolved per-year at read time and are not part of the rule.
     *
     * Returns null when there is no rule to describe.
     *
     * @param ?string $locale The locale to render in (e.g. `nl`, `fr-FR`); null autodetects.
     * @return ?string
     * @throws \InvalidArgumentException if the rule cannot be parsed, or the locale is malformed
     * and the `intl` extension is unavailable.
     * @throws \Exception if [[dtstart]] or [[timezone]] cannot be parsed into a date-time (see [[_dtstart()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function summary(?string $locale = null): ?string
    {
        if ($this->rrule === null || $this->rrule === '' || $this->dtstart === null) {
            return null;
        }

        $options = ['fallback' => 'en'];

        if ($locale !== null && $locale !== '') {
            $options['locale'] = $locale;
        }

        return (new RRule($this->rrule, $this->_dtstart()))->humanReadable($options);
    }

    /**
     * Returns the extra exclusion dates injected from elsewhere (e.g. holidays).
     *
     * @return string[]
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function getExtraExclusions(): array
    {
        return $this->_extraExclusions;
    }

    /**
     * Sets the extra exclusion dates injected from elsewhere (e.g. holidays).
     *
     * This is the one property that supports mutation after construction (see
     * the class docblock): setting it resets the memoized recurrence set so the
     * next expansion picks up the new exclusions.
     *
     * @param string[] $exclusions
     * @return void
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function setExtraExclusions(array $exclusions): void
    {
        $this->_extraExclusions = $exclusions;
        $this->_rset = null;
    }

    /**
     * Returns the occurrences of the recurrence, in the stored timezone.
     *
     * When no limit is given and the rule is infinite, the result is capped at
     * [[DEFAULT_LIMIT]] to avoid an unbounded expansion.
     *
     * @param ?int $limit Maximum number of occurrences (null or `0` means everything for a finite
     * rule; infinite rules are capped at [[DEFAULT_LIMIT]]).
     * @return DateTimeImmutable[]
     * @throws \InvalidArgumentException if the rule cannot be parsed.
     * @throws \Exception if [[dtstart]] or [[timezone]] cannot be parsed into a date-time, or an
     * exclusion/extra date cannot be parsed (see [[_rset()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function occurrences(?int $limit = null): array
    {
        $rset = $this->_rset();

        if (!$limit && $rset->isInfinite()) {
            $limit = self::DEFAULT_LIMIT;
        }

        return $this->_toImmutableList($rset->getOccurrences($limit ?? 0));
    }

    /**
     * Returns the first occurrence of the set, in the stored timezone.
     *
     * @return ?DateTimeImmutable
     * @throws \InvalidArgumentException if the rule cannot be parsed.
     * @throws \Exception if [[dtstart]] or [[timezone]] cannot be parsed into a date-time, or an
     * exclusion/extra date cannot be parsed (see [[_rset()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function firstOccurrence(): ?DateTimeImmutable
    {
        $occurrences = $this->_rset()->getOccurrences(1);

        return isset($occurrences[0]) ? $this->_toImmutable($occurrences[0]) : null;
    }

    /**
     * Returns the occurrences between two dates (inclusive of both boundaries).
     *
     * @param DateTimeInterface $from The lower boundary (inclusive).
     * @param DateTimeInterface $to The upper boundary (inclusive).
     * @param ?int $limit Maximum number of occurrences (null or `0` means everything within the range).
     * @return DateTimeImmutable[]
     * @throws \InvalidArgumentException if the rule cannot be parsed.
     * @throws \Exception if [[dtstart]] or [[timezone]] cannot be parsed into a date-time, or an
     * exclusion/extra date cannot be parsed (see [[_rset()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function occurrencesBetween(DateTimeInterface $from, DateTimeInterface $to, ?int $limit = null): array
    {
        return $this->_toImmutableList($this->_rset()->getOccurrencesBetween($from, $to, $limit ?? 0));
    }

    /**
     * Returns the first occurrence strictly after the given date.
     *
     * Cost is O(occurrences since [[dtstart]]) per call: the library has no
     * reverse iterator, so `getOccurrencesAfter()` re-expands the series from
     * its start every time. Fine for a single-value check; do not loop this
     * over many entries. The Phase 6 occurrence index is the at-scale path.
     *
     * @param ?DateTimeInterface $after The reference date, defaulting to now in the stored timezone.
     * @return ?DateTimeImmutable
     * @throws \InvalidArgumentException if the rule cannot be parsed.
     * @throws \Exception if [[dtstart]] or [[timezone]] cannot be parsed into a date-time, if `$after`
     * defaults to `now` and cannot be constructed, or an exclusion/extra date cannot be parsed
     * (see [[_rset()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function nextOccurrence(?DateTimeInterface $after = null): ?DateTimeImmutable
    {
        $after ??= new DateTimeImmutable('now', $this->_timezone());
        $occurrences = $this->_rset()->getOccurrencesAfter($after, false, 1);

        return isset($occurrences[0]) ? $this->_toImmutable($occurrences[0]) : null;
    }

    /**
     * Returns whether an occurrence starts exactly at the given date-time.
     *
     * The comparison is by instant, so the given value must match an occurrence
     * start including its time of day.
     *
     * @param DateTimeInterface $dateTime
     * @return bool
     * @throws \InvalidArgumentException if the rule cannot be parsed.
     * @throws \Exception if [[dtstart]] or [[timezone]] cannot be parsed into a date-time, or an
     * exclusion/extra date cannot be parsed (see [[_rset()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function occursAt(DateTimeInterface $dateTime): bool
    {
        // Normalize to a mutable DateTime in the stored timezone before
        // delegating: the library's occursAt() calls setTimezone() on the input
        // without reassigning the result, which is a silent no-op for
        // DateTimeImmutable and would compare the wrong wall-clock components.
        $normalized = DateTime::createFromInterface($dateTime)->setTimezone($this->_timezone());

        return $this->_rset()->occursAt($normalized);
    }

    /**
     * Returns whether an occurrence is in progress at the given date-time.
     *
     * An occurrence is active from its start until its window end. When
     * [[endTime]] is set the window is `[start, endTime]` on the occurrence day
     * (rolling to the next day if `endTime` is not after the start time). When
     * no [[endTime]] is set the occurrence is all-day and the window spans from
     * the start until the following midnight. The end boundary is exclusive.
     *
     * Cost is O(occurrences since [[dtstart]]) per call: the library has no
     * reverse iterator, so `getOccurrencesBefore()` re-expands the series from
     * its start every time. Fine for a single-value check; do not loop this
     * over many entries. The Phase 6 occurrence index is the at-scale path.
     *
     * @param DateTimeInterface $dateTime
     * @return bool
     * @throws \InvalidArgumentException if the rule cannot be parsed.
     * @throws \Exception if [[dtstart]] or [[timezone]] cannot be parsed into a date-time, or an
     * exclusion/extra date cannot be parsed (see [[_rset()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function activeAt(DateTimeInterface $dateTime): bool
    {
        $occurrences = $this->_rset()->getOccurrencesBefore($dateTime, true, 1);
        $start = $occurrences === [] ? null : $this->_toImmutable(end($occurrences));

        if ($start === null) {
            return false;
        }

        return $dateTime >= $start && $dateTime < $this->_windowEnd($start);
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds and memoizes the recurrence set.
     *
     * @return RSet
     * @throws \InvalidArgumentException if the rule, dates or exclusions cannot be parsed.
     * @throws \Exception if [[dtstart]] or [[timezone]] cannot be parsed into a date-time (see
     * [[_dtstart()]], [[_timezone()]] and [[_atOccurrenceTime()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _rset(): RSet
    {
        if ($this->_rset !== null) {
            return $this->_rset;
        }

        $rset = new RSet();

        if ($this->rrule !== null && $this->rrule !== '') {
            $rset->addRRule(new RRule($this->rrule, $this->_dtstart()));
        }

        foreach ($this->rdates as $rdate) {
            $rset->addDate($this->_atOccurrenceTime($rdate));
        }

        foreach ([...$this->exdates, ...$this->getExtraExclusions()] as $exdate) {
            $rset->addExDate($this->_atOccurrenceTime($exdate));
        }

        return $this->_rset = $rset;
    }

    /**
     * Returns the DTSTART as a mutable DateTime interpreted in the stored timezone.
     *
     * A mutable DateTime is used because the library clones and mutates it while
     * iterating; the timezone it carries is what every occurrence inherits.
     *
     * @return DateTime
     * @throws \Exception if [[dtstart]] is not a valid date-time string or [[timezone]] is not a
     * valid timezone identifier (PHP 8.3+ throws the narrower `\DateMalformedStringException` /
     * `\DateInvalidTimeZoneException`, both of which extend `\Exception`).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _dtstart(): DateTime
    {
        return new DateTime((string)$this->dtstart, $this->_timezone());
    }

    /**
     * Returns the stored timezone as a DateTimeZone.
     *
     * @return DateTimeZone
     * @throws \Exception if [[timezone]] is not a valid timezone identifier (PHP 8.3+ throws the
     * narrower `\DateInvalidTimeZoneException`, which extends `\Exception`).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _timezone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }

    /**
     * Normalizes an exclusion or extra date to the occurrence time of day.
     *
     * Stored exclusions and extras are dates, while occurrences carry the
     * DTSTART time of day. Anchoring the given date to that time in the stored
     * timezone guarantees an exact instant match against generated occurrences,
     * which is how the library matches EXDATE and RDATE values.
     *
     * @param string $date A date or date-time string.
     * @return DateTime
     * @throws \Exception if `$date` is not a valid date-time string or [[timezone]] is not a valid
     * timezone identifier (via [[_dtstart()]] and the inline `DateTime` construction; PHP 8.3+
     * throws the narrower `\DateMalformedStringException` / `\DateInvalidTimeZoneException`, both
     * of which extend `\Exception`).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _atOccurrenceTime(string $date): DateTime
    {
        $dtstart = $this->_dtstart();

        return (new DateTime($date, $this->_timezone()))
            ->setTime(
                (int)$dtstart->format('H'),
                (int)$dtstart->format('i'),
                (int)$dtstart->format('s'),
            );
    }

    /**
     * Returns the exclusive end of the active window for an occurrence start.
     *
     * @param DateTimeImmutable $start The occurrence start.
     * @return DateTimeImmutable
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _windowEnd(DateTimeImmutable $start): DateTimeImmutable
    {
        if ($this->endTime === null) {
            return $start->modify('midnight')->modify('+1 day');
        }

        [$hour, $minute] = array_pad(explode(':', $this->endTime), 2, '0');
        $end = $start->setTime((int)$hour, (int)$minute, 0);

        return $end > $start ? $end : $end->modify('+1 day');
    }

    /**
     * Converts a library-produced date into an immutable date in the same timezone.
     *
     * @param DateTimeInterface $date
     * @return DateTimeImmutable
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _toImmutable(DateTimeInterface $date): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($date);
    }

    /**
     * Converts a list of library-produced dates into immutable dates.
     *
     * @param DateTimeInterface[] $dates
     * @return DateTimeImmutable[]
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _toImmutableList(array $dates): array
    {
        return array_map(fn(DateTimeInterface $date): DateTimeImmutable => $this->_toImmutable($date), $dates);
    }
}
