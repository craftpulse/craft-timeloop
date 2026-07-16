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
 * Monthly timestring model.
 *
 * Describes the ordinal weekday refinement for monthly loops,
 * e.g. "first Monday" or "last Saturday".
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class TimeStringModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The ordinal (`first`, `second`, `third`, `fourth`, `last` or `none`).
     */
    public string $ordinal = 'none';

    /**
     * @var string The day of the week, or `none`.
     */
    public string $day = 'none';

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['ordinal', 'day'], 'string'];

        return $rules;
    }
}
