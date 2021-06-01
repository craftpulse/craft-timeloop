<?php

namespace percipiolondon\timeloop\models;

use percipiolondon\timeloop\models\PeriodModel;

use Craft;
use craft\base\Model;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;

/**
 * @author    percipiolondon
 * @package   Timeloop
 * @since     1.0.0
 */

class TimeloopModel extends Model
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

    public function getLoopStartHour()
    {
        return $this->loopEndHour['time'];
    }

    public function getPeriod()
    {

        if ($this->loopPeriod !== null) {
            $period = new PeriodModel($this->loopPeriod);

            return $period;
        }

    }

    public function getTimeString()
    {
        if ($this->loopPeriod['timestring'] !== null) {
            $timestring = new TimeStringModel($this->loopPeriod['timestring']);

            return $timestring;
        }
    }

}
