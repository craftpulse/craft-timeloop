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
 * Loop period model.
 *
 * Describes how a loop recurs: the frequency, the cycle (interval) and the
 * weekly or monthly refinements.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class PeriodModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The frequency as an ISO 8601 duration (`P1D`, `P1W`, `P1M` or `P1Y`).
     */
    public string $frequency = 'P1D';

    /**
     * @var int The cycle (interval) between occurrences.
     */
    public int $cycle = 1;

    /**
     * @var array The selected days of the week for the weekly frequency.
     */
    public array $days = [];

    /**
     * @var array The ordinal and day configuration for the monthly frequency.
     */
    public array $timestring = [];

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['frequency'], 'string'];
        $rules[] = [['days', 'timestring'], 'safe'];
        $rules[] = [['cycle'], 'integer', 'min' => 1];

        return $rules;
    }
}
