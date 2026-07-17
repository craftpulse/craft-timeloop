<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\variables;

use craft\base\ElementInterface;
use craft\base\Model;
use craft\helpers\UrlHelper;
use craftpulse\timeloop\models\TimeloopModel;
use craftpulse\timeloop\Timeloop;
use DateTime;

/**
 * Timeloop template variable, available as `craft.timeloop`.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class TimeloopVariable
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the loop period configuration of the given field value.
     *
     * @param TimeloopModel $data
     * @return ?Model
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function period(TimeloopModel $data): ?Model
    {
        return Timeloop::$plugin->timeloop->showPeriod($data);
    }

    /**
     * Returns the first upcoming date of the given field value.
     *
     * @param TimeloopModel $data
     * @return ?DateTime
     * @throws \Exception
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getUpcoming(TimeloopModel $data): ?DateTime
    {
        $upcoming = Timeloop::$plugin->timeloop->getLoop($data, 1);

        return $upcoming[0] ?? null;
    }

    /**
     * Returns the reminder date for the first upcoming occurrence.
     *
     * @param TimeloopModel $data
     * @return ?DateTime
     * @throws \Exception
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getReminder(TimeloopModel $data): ?DateTime
    {
        return Timeloop::$plugin->timeloop->getReminder($data);
    }

    /**
     * Returns the recurrence dates for the given field value.
     *
     * @param TimeloopModel $data
     * @param int $limit Maximum number of dates to return, `0` falls back to the service default.
     * @param bool $futureDates Whether only dates after now should be returned.
     * @return ?array
     * @throws \Exception
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getDates(TimeloopModel $data, int $limit = 0, bool $futureDates = true): ?array
    {
        return Timeloop::$plugin->timeloop->getLoop($data, $limit, $futureDates);
    }

    /**
     * Returns a signed, public ICS-download URL for an element's Timeloop field.
     *
     * Usage in Twig: `craft.timeloop.icsUrl(entry, 'fieldHandle')`. The URL
     * carries an HMAC token over the element/site/field triple (see
     * {@see \craftpulse\timeloop\services\IcsService::signToken()}); it needs no
     * session and exposes no enumerable identifiers. The site ID defaults to the
     * element's own site.
     *
     * @param ElementInterface $element The element carrying the field.
     * @param string $fieldHandle The Timeloop field handle.
     * @param ?int $siteId The site ID to read the value in, or null for the element's site.
     * @return string The absolute ICS-download URL.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function icsUrl(ElementInterface $element, string $fieldHandle, ?int $siteId = null): string
    {
        $token = Timeloop::$plugin->getIcs()->signToken(
            (int)$element->id,
            $siteId ?? (int)$element->siteId,
            $fieldHandle,
        );

        return UrlHelper::actionUrl('timeloop/ics', ['sig' => $token]);
    }
}
