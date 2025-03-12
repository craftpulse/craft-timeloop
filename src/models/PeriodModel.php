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
 */

class PeriodModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public string $frequency;

    /**
     * @var integer
     */
    public int $cycle;

    /**
     * @var array
     */
    public array $days;

    /**
     * @var array
     */
    public array $timestring;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['frequency'], 'string'];
        $rules[] = [['days', 'timestring'], 'array'];
        $rules[] = [['cycle'], 'number'];

        return $rules;
    }
}
