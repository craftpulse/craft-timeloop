<?php

namespace percipiolondon\timeloop\models;

use Craft;
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
     * @var array
     */
    public $days;

    /**
     * @var number
     */
    public $cycle;

    /**
     * @var array
     */
    public $timestring;

    // Public Methods
    // =========================================================================

    public function rules()
    {
        return [
            ['frequency', 'string'],
            ['days', 'array'],
            ['cycle', 'number'],
            ['timestring', 'array'],
        ];
    }

}
