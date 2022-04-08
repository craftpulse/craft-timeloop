<?php

namespace percipiolondon\timeloop\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;
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
     * @var int
     */
    public int $loopReminderValue;

    /**
     * @var array
     */
    public array $loopPeriod;

    /**
     * @var array
     */
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

    public function getPeriod(): ?PeriodModel
    {
        if ($this->loopPeriod !== null) {
            return new PeriodModel($this->loopPeriod);
        }
        return null;
    }

    public function getTimeString(): ?TimeStringModel
    {
        if ($this->loopPeriod['timestring'] !== null) {
            return new TimeStringModel($this->loopPeriod['timestring']);
        }
        return null;
    }

    public function getLoopStartTime(): ?string
    {
        $value = DateTimeHelper::toDateTime($this->loopStartTime);
        return $value?->format('H:i');
    }

    public function getLoopEndTime(): ?string
    {
        $value = DateTimeHelper::toDateTime($this->loopEndTime);
        return $value?->format('H:i');
    }

    public function getReminder(): ?DateTime
    {
        return Timeloop::$plugin->timeloop->getReminder($this);
    }

    public function getDates(int $limit = 0, bool $futureDates = true): ?array
    {
        return Timeloop::$plugin->timeloop->getLoop($this, $limit, $futureDates);
    }

    public function getUpcoming(): ?string
    {
        if (count($this->upcomingDates) > 1) {
            return $this->upcomingDates[0];
        }

        return null;
    }

    public function getNextUpcoming(): ?string
    {
        if (count($this->upcomingDates) > 1) {
            return $this->upcomingDates[1];
        }

        return null;
    }
}
