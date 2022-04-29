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
     * @var DateTime|bool
     */
    public DateTime|bool $loopStartTime;

    /**
     * @var DateTime|bool
     */
    public DateTime|bool $loopEndTime;

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

        $rules[] = [['loopStartDate'], 'required'];
        $rules[] = [['loopStartDate', 'loopEndDate'], 'datetime'];
        $rules[] = [['loopStartTime', 'loopEndTime'], 'datetime', 'boolean'];
        $rules[] = [['loopPeriod'], 'array'];
        $rules[] = [['loopReminderValue'], 'integer'];

        return $rules;
    }

    /**
     * @throws \yii\base\Exception
     */
    public function init(): void
    {
        if (!empty($this->loopStartDate) && !empty($this->loopEndDate)) {
            $this->upcomingDates = Timeloop::$plugin->timeloop->getLoop($this, 2, true);
        }
    }

    /**
     * @return PeriodModel|null
     */
    public function getPeriod(): ?PeriodModel
    {
        if ($this->loopPeriod !== []) {
            return new PeriodModel($this->loopPeriod);
        }
        return null;
    }

    /**
     * @return TimeStringModel|null
     */
    public function getTimeString(): ?TimeStringModel
    {
        if ($this->loopPeriod['timestring'] !== null) {
            return new TimeStringModel($this->loopPeriod['timestring']);
        }
        return null;
    }

    /**
     * @return string|null
     * @throws \Exception
     */
    public function getLoopStartTime(): ?string
    {
        $value = DateTimeHelper::toDateTime($this->loopStartTime);
        return $value ? $value->format('H:i') : null;
    }

    /**
     * @return string|null
     * @throws \Exception
     */
    public function getLoopEndTime(): ?string
    {
        $value = DateTimeHelper::toDateTime($this->loopEndTime);
        return $value ? $value->format('H:i') : null;
    }

    /**
     * @return DateTime|null
     * @throws \yii\base\Exception
     */
    public function getReminder(): ?DateTime
    {
        return Timeloop::$plugin->timeloop->getReminder($this);
    }

    /**
     * @param int $limit
     * @param bool $futureDates
     * @return array|null
     * @throws \yii\base\Exception
     */
    public function getDates(int $limit = 0, bool $futureDates = true): ?array
    {
        return Timeloop::$plugin->timeloop->getLoop($this, $limit, $futureDates);
    }

    /**
     * @return string|null
     */
    public function getUpcoming(): ?string
    {
        if (count($this->upcomingDates) > 1) {
            return $this->upcomingDates[0];
        }

        return null;
    }

    /**
     * @return string|null
     */
    public function getNextUpcoming(): ?string
    {
        if (count($this->upcomingDates) > 1) {
            return $this->upcomingDates[1];
        }

        return null;
    }
}
