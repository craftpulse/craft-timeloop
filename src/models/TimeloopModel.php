<?php

namespace percipiolondon\timeloop\models;

use percipiolondon\timeloop\Timeloop;
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
     * @var DateTime
     */
    public $loopStartHour;

    /**
     * @var DateTime
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
            ['loopStartHour', 'datetime'],
            ['loopEndHour', 'datetime'],
            ['loopReminderValue', 'number'],
        ];
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

    public function getLoopStartHour()
    {
        $value = DateTimeHelper::toDateTime($this->loopStartHour);
        return  $value ? $value->format('G:i') : false;
    }

    public function getLoopEndHour()
    {
        $value = DateTimeHelper::toDateTime($this->loopEndHour);
        return  $value ? $value->format('G:i') : false;
    }

    public function getReminder()
    {
        return Timeloop::$plugin->timeloop->getReminder($this);
    }

    public function getDates(int $limit = 0, bool $futureDates = true)
    {
        return Timeloop::$plugin->timeloop->getLoop($this, $limit, $futureDates);
    }

    public function getUpcoming()
    {
        return Timeloop::$plugin->timeloop->getLoop($this, 1);
    }
}
