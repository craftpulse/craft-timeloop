<?php

namespace percipiolondon\timeloop\models;

use craft\base\Model;

/**
 * @author    percipiolondon
 * @package   Timeloop
 * @since     1.0.0
 */

class PeriodModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public $frequency;

    /**
     * @var integer
     */
    public $cycle;

    /**
     * @var array
     */
    public $days;

    /**
     * @var array
     */
    public $timestring;

    // Public Methods
    // =========================================================================

    public function rules(): array
    {
        return [
            ['frequency', 'string'],
            ['days', 'array'],
            ['cycle', 'number'],
            ['timestring', 'array'],
        ];
    }
}
