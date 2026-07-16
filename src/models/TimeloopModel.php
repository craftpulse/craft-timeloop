<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use craftpulse\timeloop\Timeloop;
use DateTime;

/**
 * Timeloop field value model.
 *
 * Holds the loop configuration (start and end dates, times, period and
 * reminder settings) and exposes the computed recurrence dates.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class TimeloopModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var ?DateTime The date the loop starts.
     */
    public ?DateTime $loopStartDate = null;

    /**
     * @var ?DateTime The date the loop ends.
     */
    public ?DateTime $loopEndDate = null;

    /**
     * @var ?DateTime The time of day each occurrence starts.
     */
    public ?DateTime $loopStartTime = null;

    /**
     * @var ?DateTime The time of day each occurrence ends.
     */
    public ?DateTime $loopEndTime = null;

    /**
     * @var ?string The reminder period unit (e.g. `days`, `weeks`).
     */
    public ?string $loopReminderPeriod = null;

    /**
     * @var ?int The reminder period value.
     */
    public ?int $loopReminderValue = null;

    /**
     * @var ?array The loop period configuration (frequency, cycle, days, timestring).
     */
    public ?array $loopPeriod = null;

    // Private Properties
    // =========================================================================

    /**
     * @var ?array Memoized upcoming dates.
     */
    private ?array $_upcomingDates = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the loop period configuration as a model.
     *
     * @return ?PeriodModel
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getPeriod(): ?PeriodModel
    {
        if (empty($this->loopPeriod)) {
            return null;
        }

        return new PeriodModel($this->loopPeriod);
    }

    /**
     * Returns the monthly timestring configuration as a model.
     *
     * @return ?TimeStringModel
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getTimeString(): ?TimeStringModel
    {
        if (empty($this->loopPeriod['timestring'])) {
            return null;
        }

        return new TimeStringModel($this->loopPeriod['timestring']);
    }

    /**
     * Returns the loop start time formatted as `H:i`.
     *
     * @return ?string
     * @throws \Exception
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getLoopStartTime(): ?string
    {
        $value = DateTimeHelper::toDateTime($this->loopStartTime);

        return $value ? $value->format('H:i') : null;
    }

    /**
     * Returns the loop start time as a DateTime object.
     *
     * @return ?DateTime
     * @throws \Exception
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getLoopStart(): ?DateTime
    {
        $value = DateTimeHelper::toDateTime($this->loopStartTime);

        return $value === false ? null : $value;
    }

    /**
     * Returns the loop end time formatted as `H:i`.
     *
     * @return ?string
     * @throws \Exception
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getLoopEndTime(): ?string
    {
        $value = DateTimeHelper::toDateTime($this->loopEndTime);

        return $value ? $value->format('H:i') : null;
    }

    /**
     * Returns the loop end time as a DateTime object.
     *
     * @return ?DateTime
     * @throws \Exception
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getLoopEnd(): ?DateTime
    {
        $value = DateTimeHelper::toDateTime($this->loopEndTime);

        return $value === false ? null : $value;
    }

    /**
     * Returns the reminder date for the first upcoming occurrence.
     *
     * @return ?DateTime
     * @throws \Exception
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getReminder(): ?DateTime
    {
        return Timeloop::$plugin->timeloop->getReminder($this);
    }

    /**
     * Returns the computed recurrence dates.
     *
     * @param int $limit
     * @param bool $futureDates
     * @return ?array
     * @throws \Exception
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getDates(int $limit = 0, bool $futureDates = true): ?array
    {
        return Timeloop::$plugin->timeloop->getLoop($this, $limit, $futureDates);
    }

    /**
     * Returns the first upcoming occurrence.
     *
     * @return ?DateTime
     * @throws \Exception
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getUpcoming(): ?DateTime
    {
        return $this->_getUpcomingDates()[0] ?? null;
    }

    /**
     * Returns the second upcoming occurrence.
     *
     * @return ?DateTime
     * @throws \Exception
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getNextUpcoming(): ?DateTime
    {
        return $this->_getUpcomingDates()[1] ?? null;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['loopStartDate'], 'required'];
        $rules[] = [['loopStartDate', 'loopEndDate'], 'datetime'];
        $rules[] = [['loopPeriod'], 'safe'];
        $rules[] = [['loopReminderValue'], 'integer'];

        return $rules;
    }

    // Private Methods
    // =========================================================================

    /**
     * Memoizes and returns the next two upcoming occurrences.
     *
     * @return array
     * @throws \Exception
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _getUpcomingDates(): array
    {
        if ($this->_upcomingDates === null) {
            $this->_upcomingDates = Timeloop::$plugin->timeloop->getLoop($this, 2) ?? [];
        }

        return $this->_upcomingDates;
    }
}
