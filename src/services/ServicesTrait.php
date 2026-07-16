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

use yii\base\InvalidConfigException;

/**
 * Typed accessors for the plugin's service components.
 *
 * Keeps `Timeloop::getInstance()->getTimeloop()` / `->getHolidays()` statically
 * typed with a runtime assertion, instead of `@property` tags over untyped
 * `get()` calls.
 *
 * @author CraftPulse
 * @since 5.1.0
 */
trait ServicesTrait
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the Timeloop service.
     *
     * @return TimeloopService
     * @throws InvalidConfigException if the component is misconfigured.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function getTimeloop(): TimeloopService
    {
        $service = $this->get('timeloop');
        assert($service instanceof TimeloopService);

        return $service;
    }

    /**
     * Returns the Holidays service.
     *
     * @return HolidaysService
     * @throws InvalidConfigException if the component is misconfigured.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function getHolidays(): HolidaysService
    {
        $service = $this->get('holidays');
        assert($service instanceof HolidaysService);

        return $service;
    }
}
