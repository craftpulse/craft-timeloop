<?php

namespace percipiolondon\timeloop\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;
use nystudio107\seomatic\models\jsonld\Date;
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
     * @var DateTime|null
     */
    public DateTime|null $loopStartDate = null;

    /**
     * @var DateTime|null
     */
    public DateTime|null $loopEndDate = null;

    /**
     * @var DateTime|null
     */
    public DateTime|null $loopStartTime = null;

    /**
     * @var DateTime|null
     */
    public DateTime|null $loopEndTime = null;

    /**
     * @var string|null
     */
    public string|null $loopReminderPeriod = null;

    /**
     * @var int|null
     */
    public int|null $loopReminderValue = null;

    /**
     * @var array|null
     */
    public array|null $loopPeriod = null;

    /**
     * @var array|null
     */
    private array|null $upcomingDates = null;


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
//        $rules[] = [['loopStartTime', 'loopEndTime'], 'datetime', 'null'];
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
     * @return bool
     */
    public function beforeValidate(): bool
    {
        return parent::beforeValidate(); // TODO: Change the autogenerated stub
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
     * @return DateTime|null
     * @throws \Exception
     */
    public function getLoopStart(): ?string
    {
        $value = DateTimeHelper::toDateTime($this->loopStartTime);
        return $value === false ? null : $value;
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
     * @throws \Exception
     */
    public function getLoopEnd(): ?string
    {
        $value = DateTimeHelper::toDateTime($this->loopEndTime);
        return $value === false ? null : $value;
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
     * @return DateTime|null
     */
    public function getUpcoming(): ?DateTime
    {
        if (count($this->upcomingDates) > 1) {
            return $this->upcomingDates[0];
        }

        return null;
    }

    /**
     * @return DateTime|null
     */
    public function getNextUpcoming(): ?DateTime
    {
        if (count($this->upcomingDates) > 1) {
            return $this->upcomingDates[1];
        }

        return null;
    }
}
