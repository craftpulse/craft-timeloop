<?php

namespace percipiolondon\timeloop\models;

use Arrayy\Arrayy;
use percipiolondon\timeloop\Timeloop;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;

/**
 * @author    percipiolondon
 * @package   Timeloop
 * @since     1.0.0
 *
 * @property-read \percipiolondon\timeloop\models\PeriodModel $period
 * @property-read mixed $reminder
 * @property-read false|mixed $nextUpcoming
 * @property-read \percipiolondon\timeloop\models\TimeStringModel $timeString
 * @property-read false|mixed $upcoming
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

    /**
     * @var
     */
    private $upcomingDates;


    // Public Methods
    // =========================================================================

    /**
     * @return \string[][]
     */
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

    /**
     *
     */
    public function init() {

        if ( $this->loopStartDate && $this->loopEndDate ) {
            $this->upcomingDates = Timeloop::$plugin->timeloop->getLoop($this, 2, true);
        }

    }

    /**
     * @return PeriodModel
     */
    public function getPeriod()
    {
        if ($this->loopPeriod !== null) {
            return new PeriodModel($this->loopPeriod);
        }
    }

    /**
     * @return TimeStringModel
     */
    public function getTimeString()
    {
        if ($this->loopPeriod['timestring'] !== null) {
            return new TimeStringModel($this->loopPeriod['timestring']);
        }
    }

    /**
     * @return false|string
     * @throws \Exception
     */
    public function getLoopStartTime()
    {
        $value = DateTimeHelper::toDateTime($this->loopStartTime);
        return  $value ? $value->format('H:i') : false;
    }

    /**
     * @return false|string
     * @throws \Exception
     */
    public function getLoopEndTime()
    {
        $value = DateTimeHelper::toDateTime($this->loopEndTime);
        return  $value ? $value->format('H:i') : false;
    }

    /**
     * @return mixed
     */
    public function getReminder()
    {
        return Timeloop::$plugin->timeloop->getReminder($this);
    }

    /**
     * @param int $limit
     * @param bool $futureDates
     * @return mixed
     */
    public function getDates(int $limit = 0, bool $futureDates = true)
    {
        return Timeloop::$plugin->timeloop->getLoop($this, $limit, $futureDates);
    }

    /**
     * @return false|mixed
     */
    public function getUpcoming()
    {
        if ( count($this->upcomingDates) > 1 ) {
            return $this->upcomingDates[0];
        }

        return false;
    }

    /**
     * @return false|mixed
     */
    public function getNextUpcoming()
    {
        if ( count($this->upcomingDates) > 1 ) {
            return $this->upcomingDates[1];
        }

        return false;
    }

}
