<?php

namespace percipiolondon\timeloop\models;

use Craft;
use craft\base\Model;
use craft\helpers\Json;

/**
 * @author    percipiolondon
 * @package   Timeloop
 * @since     1.0.0
 */

class LoopPeriod extends Model
{

    // Public Properties
    // =========================================================================

    /**
     * @var DateTime
     */
    public $loopPeriod;

    // Public Methods
    // =========================================================================

    public function rules()
    {
        return [

        ];
    }

    /**
     * Render the JSON Structured Data for the loopPeriod
     *
     * @param bool $raw
     *
     */
    public function getLoopPeriod()
    {

        return $loopPeriod;

    }

}
