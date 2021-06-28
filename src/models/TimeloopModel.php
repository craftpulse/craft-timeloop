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
    public $loopStartDate;

    /**
     * @var DateTime
     */
    public $loopEndDate;

    /**
     * @var DateTime
     */
    public $loopStartTime;

    /**
     * @var DateTime
     */
    public $loopEndTime;

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

    private $upcomingDates;


    // Public Methods
    // =========================================================================

    public function rules()
    {
        return [
            ['loopStartDate', 'datetime'],
            ['loopEndDate', 'datetime'],
            ['loopPeriod', 'array'],
            ['loopStartTime', 'datetime'],
            ['loopEndTime', 'datetime'],
            ['loopReminderValue', 'integer'],
        ];
    }

    public function init() {

            if ( $this->loopStartDate && $this->loopEndDate ) {
                $this->upcomingDates = Timeloop::$plugin->timeloop->getLoop($this, 2, true);
            }

    }

    /**
     * Use the handle as the string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        return 'magical to string function for entry.timeloop';
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

    public function getLoopStartTime()
    {
        $value = DateTimeHelper::toDateTime($this->loopStartTime);
        return  $value ? $value->format('H:i') : false;
    }

    public function getLoopEndTime()
    {
        $value = DateTimeHelper::toDateTime($this->loopEndTime);
        return  $value ? $value->format('H:i') : false;
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
        if ( count($this->upcomingDates) > 1 ) {
            return $this->upcomingDates[0];
        } else {
            return false;
        }
    }

    public function getNextUpcoming()
    {
        if ( count($this->upcomingDates) > 1 ) {
            return $this->upcomingDates[1];
        } else {
            return false;
        }
    }

}
