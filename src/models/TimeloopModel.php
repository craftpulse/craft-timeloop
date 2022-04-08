<?php

namespace percipiolondon\timeloop\models;

use craft\base\Model;

use craft\helpers\DateTimeHelper;
use percipiolondon\timeloop\Timeloop;

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
    public DateTime $loopStartDate;

    /**
     * @var DateTime
     */
    public DateTime $loopEndDate;

    /**
     * @var DateTime
     */
    public DateTime $loopStartTime;

    /**
     * @var DateTime
     */
    public DateTime $loopEndTime;

    /**
     * @var string
     */
    public string $loopReminderPeriod;

    /**
     * @var number
     */
    public number $loopReminderValue;

    /**
     * @var array
     */
    public array $loopPeriod;

    private array $upcomingDates;


    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['loopStartDate', 'loopEndDate', 'loopStartTime', 'loopEndTime'], 'datetime'];
        $rules[] = [['loopPeriod'], 'array'];
        $rules[] = [['loopReminderValue'], 'integer'];

        return $rules;
    }

    public function init(): void
    {
        if ($this->loopStartDate && $this->loopEndDate) {
            $this->upcomingDates = Timeloop::$plugin->timeloop->getLoop($this, 2, true);
        }
    }

    public function getPeriod()
    {
        if ($this->loopPeriod !== null) {
            return new PeriodModel($this->loopPeriod);
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
        if (count($this->upcomingDates) > 1) {
            return $this->upcomingDates[0];
        } else {
            return false;
        }
    }

    public function getNextUpcoming()
    {
        if (count($this->upcomingDates) > 1) {
            return $this->upcomingDates[1];
        } else {
            return false;
        }
    }
}
