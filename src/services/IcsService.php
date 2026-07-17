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

use Craft;
use craft\base\Component;
use craftpulse\timeloop\models\RecurrenceModel;
use craftpulse\timeloop\models\TimeloopModel;
use craftpulse\timeloop\Timeloop;
use DateTimeImmutable;
use DateTimeZone;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;
use yii\base\Security;

/**
 * ICS (iCalendar) export service.
 *
 * The single place the `Spatie\IcalendarGenerator\*` namespace appears. Turns a
 * normalized {@see TimeloopModel} field value into an RFC 5545 `VCALENDAR`
 * carrying one recurring `VEVENT`:
 *
 * - the `RRULE` comes verbatim from the engine's
 *   {@see RecurrenceModel::rfcString()} (never hand-assembled);
 * - `DTSTART`/`DTEND` are timezone-correct (the value's IANA timezone, which the
 *   library emits as a matching `VTIMEZONE`); an all-day value (no `endTime`)
 *   produces a full-day event;
 * - every exclusion instant, static exdates *and* the holidays resolved for the
 *   value's horizon, is emitted as an `EXDATE`. Holidays are deliberately baked
 *   in here (unlike at read time, where they stay dynamic): an offline calendar
 *   client cannot call the holiday service, so the exclusions must travel with
 *   the file. The recurrence is therefore resolved through the holiday-aware
 *   {@see TimeloopService::recurrenceFor()}.
 *
 * @author CraftPulse
 * @since 5.1.0
 */
class IcsService extends Component
{
    // Public Properties
    // =========================================================================

    /**
     * @var ?Security The security component used to sign and validate ICS tokens.
     *
     * Injectable via the component config (`new IcsService(['security' => ...])`),
     * so the standalone Pest suite can exercise [[signToken()]]/[[validateToken()]]
     * with a bare `new \yii\base\Security()` and no booted Craft application;
     * [[_security()]] defaults it to `Craft::$app->getSecurity()` on first use.
     * The same injectable-dependency seam as {@see HolidaysService}'s guarded
     * Craft lookups.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public ?Security $security = null;

    /**
     * @var ?string The key `hashData()`/`validateData()` sign and verify with.
     *
     * `craft\services\Security::hashData()`/`validateData()` default a null
     * key to `Craft::$app->getConfig()->getGeneral()->securityKey` themselves,
     * but the base `yii\base\Security` (what [[$security]] is injected with in
     * the standalone Pest suite) requires an explicit key on every call: there
     * is no app config for it to fall back to. The key is therefore resolved
     * once, here, and always passed explicitly, so both a Craft-booted and a
     * standalone [[$security]] behave identically. [[_securityKey()]] defaults
     * it to the app's own `securityKey` on first use.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public ?string $securityKey = null;

    // Public Methods
    // =========================================================================

    /**
     * Builds the ICS calendar body for a single Timeloop field value.
     *
     * @param TimeloopModel $value The normalized field value.
     * @param ?string $summary The event summary (typically the owning entry's title).
     * @param ?string $uid A stable unique identifier for the event, or null to let the library assign one.
     * @return string The serialized `VCALENDAR`.
     * @throws \Exception if the value's `dtstart`/`timezone` cannot be parsed, or the recurrence
     * cannot be resolved (see {@see TimeloopService::recurrenceFor()} and {@see RecurrenceModel}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function calendarFor(TimeloopModel $value, ?string $summary = null, ?string $uid = null): string
    {
        $event = $this->_event($value, $summary, $uid);

        return Calendar::create()
            ->event($event)
            ->get();
    }

    /**
     * Signs a tamper-proof ICS-download token for an element's field value.
     *
     * The token is an HMAC (keyed with the app security key) over the
     * `elementId|siteId|fieldHandle` triple via
     * {@see \craft\services\Security::hashData()}, so a public ICS URL carries
     * no enumerable, forgeable identifiers: changing any part invalidates the
     * signature. Mirrors how Craft signs its own share/preview tokens.
     *
     * @param int $elementId The owning element ID.
     * @param int $siteId The site ID the value is read in.
     * @param string $fieldHandle The Timeloop field handle.
     * @return string The signed token.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function signToken(int $elementId, int $siteId, string $fieldHandle): string
    {
        return $this->_security()->hashData($this->_payload($elementId, $siteId, $fieldHandle), $this->_securityKey());
    }

    /**
     * Validates a signed ICS-download token and returns its triple, or null.
     *
     * @param string $token The signed token.
     * @return ?array{elementId: int, siteId: int, fieldHandle: string} The decoded triple, or null when invalid.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function validateToken(string $token): ?array
    {
        $data = $this->_security()->validateData($token, $this->_securityKey());

        if ($data === false) {
            return null;
        }

        $parts = explode('|', $data, 3);

        if (count($parts) !== 3 || $parts[2] === '') {
            return null;
        }

        return [
            'elementId' => (int)$parts[0],
            'siteId' => (int)$parts[1],
            'fieldHandle' => $parts[2],
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the canonical signing payload for a field value.
     *
     * @param int $elementId
     * @param int $siteId
     * @param string $fieldHandle
     * @return string
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _payload(int $elementId, int $siteId, string $fieldHandle): string
    {
        return implode('|', [$elementId, $siteId, $fieldHandle]);
    }

    /**
     * Returns the security component used to sign and validate tokens.
     *
     * Prefers the injected [[$security]] (see the property docblock) and
     * defaults to `Craft::$app->getSecurity()` on first use, memoizing the
     * result onto [[$security]] itself.
     *
     * @return Security
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _security(): Security
    {
        return $this->security ??= Craft::$app->getSecurity();
    }

    /**
     * Returns the key used to sign and validate tokens.
     *
     * Prefers the injected [[$securityKey]] (see the property docblock) and
     * defaults to `Craft::$app->getConfig()->getGeneral()->securityKey` on
     * first use, memoizing the result onto [[$securityKey]] itself.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _securityKey(): string
    {
        return $this->securityKey ??= Craft::$app->getConfig()->getGeneral()->securityKey;
    }

    /**
     * Builds the recurring `VEVENT` for a field value.
     *
     * @param TimeloopModel $value The normalized field value.
     * @param ?string $summary The event summary.
     * @param ?string $uid A stable unique identifier, or null.
     * @return Event
     * @throws \Exception if the value's `dtstart`/`timezone` cannot be parsed, or the recurrence
     * cannot be resolved (see {@see TimeloopService::recurrenceFor()} and {@see RecurrenceModel}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _event(TimeloopModel $value, ?string $summary, ?string $uid): Event
    {
        $timezone = new DateTimeZone($value->timezone);
        $start = new DateTimeImmutable((string)$value->dtstart, $timezone);

        $event = Event::create($summary ?? '')->startsAt($start);

        if ($uid !== null) {
            $event->uniqueIdentifier($uid);
        }

        if ($value->endTime === null) {
            $event->fullDay();
        } else {
            $event->endsAt($this->_windowEnd($start, $value->endTime));
        }

        $recurrence = $this->_recurrenceFor($value);

        if ($recurrence !== null) {
            $this->_applyRecurrence($event, $recurrence, $start, $value);
        }

        return $event;
    }

    /**
     * Resolves the recurrence for a value, holiday-aware when a plugin is booted.
     *
     * Prefers {@see TimeloopService::recurrenceFor()} (which bakes in the
     * resolved public holidays) and falls back to the value's own bare
     * recurrence when no plugin is registered, so the ICS body stays generatable
     * from the standalone unit context. Mirrors the guarded lookups in
     * {@see TimeloopService} and {@see TimeloopModel}.
     *
     * @param TimeloopModel $value
     * @return ?RecurrenceModel
     * @throws \Exception if the recurrence cannot be resolved (see {@see TimeloopService::recurrenceFor()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _recurrenceFor(TimeloopModel $value): ?RecurrenceModel
    {
        try {
            $plugin = Timeloop::getInstance();

            if ($plugin !== null && $plugin->has('timeloop')) {
                return $plugin->getTimeloop()->recurrenceFor($value);
            }
        } catch (\Throwable) {
            // No booted plugin (e.g. the standalone unit context); fall through.
        }

        return $value->getRecurrence();
    }

    /**
     * Applies the RRULE and EXDATEs of a recurrence onto an event.
     *
     * @param Event $event The event to mutate.
     * @param RecurrenceModel $recurrence The holiday-aware recurrence.
     * @param DateTimeImmutable $start The event start (RRULE anchor).
     * @param TimeloopModel $value The field value (source of the `UNTIL` boundary).
     * @return void
     * @throws \Exception if the rule or an exclusion date cannot be parsed (see {@see RecurrenceModel}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _applyRecurrence(Event $event, RecurrenceModel $recurrence, DateTimeImmutable $start, TimeloopModel $value): void
    {
        $rrule = $recurrence->rfcString();

        if ($rrule === null) {
            return;
        }

        $until = $value->loopEndDate !== null ? DateTimeImmutable::createFromInterface($value->loopEndDate) : null;
        $event->rruleAsString($rrule, $start, $until);

        $exclusions = $recurrence->exclusionDates();

        if ($exclusions !== []) {
            $event->doNotRepeatOn($exclusions);
        }
    }

    /**
     * Returns the occurrence window end for a timed event.
     *
     * Mirrors {@see RecurrenceModel} window semantics: an `endTime` at or before
     * the start time rolls to the next day.
     *
     * @param DateTimeImmutable $start The occurrence start.
     * @param string $endTime The wall-clock end time, formatted `H:i`.
     * @return DateTimeImmutable
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _windowEnd(DateTimeImmutable $start, string $endTime): DateTimeImmutable
    {
        [$hour, $minute] = array_pad(explode(':', $endTime), 2, '0');
        $end = $start->setTime((int)$hour, (int)$minute, 0);

        return $end > $start ? $end : $end->modify('+1 day');
    }
}
