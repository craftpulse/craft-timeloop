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

class Timeloop extends Model
{

    // Public Properties
    // =========================================================================

    /**
     * @var DateTime
     */
    public $loopStart;

    /**
     * @var DateTime
     */
    public $loopEnd;

    /**
     * @var string
     */
    public $loopEndHour;

    /**
     * @var string
     */
    public $loopReminderPeriod;

    /**
     * @var number
     */
    public $loopReminderValue;

    /**
     * @var array
     */
    public $loopPeriod;

    

    // Public Methods
    // =========================================================================

    public function rules()
    {
        return [
            ['loopStart', 'datetime'],
            ['loopEnd', 'datetime'],
            ['loopPeriod', 'array'],
            ['loopEndHour', 'string'],
            ['loopReminderValue', 'number'],
        ];
    }

    /**
     * Render the JSON Structured Data for the loopPeriod
     *
     * @param bool $raw
     *
     */
    public function renderLoopPeriod()
    {
        $loopPeriod = craft\helpers\Json::decode(craft\helpers\Json::decode($this->loopPeriod));
        return $loopPeriod;

    }

    /**
     * Render the JSON Structured Data for the loopPeriod
     *
     * @param bool $raw
     *
     * @return string|\Twig_Markup
     */

}
