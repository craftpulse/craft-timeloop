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

use craft\base\Model;
use craftpulse\timeloop\models\TimeloopModel;
use craftpulse\timeloop\Timeloop;
use DateTime;
use nystudio107\pluginvite\variables\ViteVariableInterface;
use nystudio107\pluginvite\variables\ViteVariableTrait;

/**
 * Timeloop template variable, available as `craft.timeloop`.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class TimeloopVariable implements ViteVariableInterface
{
    // Traits
    // =========================================================================

    use ViteVariableTrait;

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
}
