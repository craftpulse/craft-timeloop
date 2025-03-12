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

/**
 * @author    craftpulse
 * @package   Timeloop
 * @since     1.0.0
 */

class TimeStringModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public string $ordinal;

    /**
     * @var string
     */
    public string $day;

    // Public Methods
    // =========================================================================

    /**
     * @return array
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['ordinal'], 'string'];
        $rules[] = [['day', 'loopPeriod'], 'array'];
        $rules[] = [['loopStartTime', 'loopEndTime'], 'datetime'];
        $rules[] = [['loopReminderValue'], 'integer'];

        return $rules;
    }
}
