<?php

namespace percipiolondon\timeloop\services;

use Craft;
use craft\base\Component;
use craft\helpers\DateTimeHelper;
use craft\helpers\Gql;
use DateInterval;
use DateTime;
use DatePeriod;
use percipiolondon\timeloop\models\TimeloopModel;

/**
 * @author    percipiolondon
 * @package   Timeloop
 * @since     1.0.0
 */

class TimeloopService extends Component
{
    const MAX_ARRAY_ENTRIES = 100;

    // Public Methods
    // =========================================================================

    /**
     * Returns the $limit upcoming dates from the timeloop start date
     *
     * @param array $data
     *
     */
    public function showPeriod(array $data)
    {
        return $data->period;
    }

    /**
     * Returns the $limit upcoming dates from the timeloop start date
     *
     * @param TimeloopModel $data
     * @param bool $futureDates
     * @param integer $limit
     *
     */
    public function getLoop(TimeloopModel $data, $limit = 0, bool $futureDates = true)
    {
        //  get start date from data object
        //  $startUnix = strtotime($data['loopStart']['date']);

        // check if the end date is set in data object, otherwise use today + 20 years as default to get way ahead in the future
        $next = new DateTime();
        $end = $data['loopEnd'] instanceof \DateTime ?
            $data['loopEnd']:
            $next->modify('+20 years');

        // get ISO 8601 from the repeater in data object
        // Parse our period object to fetch dates
        $repeater = $data->period ?? false;

        // if no limit is set, use the default so we don't end up with high number arrays
        $limit = $limit == 0 ? self::MAX_ARRAY_ENTRIES : $limit;

        // check if repeater exist, throw exception is no value is added
        if(!$repeater) {
            throw new \yii\base\Exception( "There's no correct repeater value set. Use P1D / P1W / P1M / P1Y." );
        }

        // return the array with dates
        return $this->_fetchDates($data->loopStart, $data->loopStartHour, $end, $repeater, $limit, $futureDates);
    }

    /**
     * @param TimeloopModel $data
     * @return mixed|null
     * @throws \yii\base\Exception
     */
    public function getReminder(TimeloopModel $data)
    {
        $date = $this->getLoop($data, 1);
        $loopReminderValue = $data->loopReminderValue ?? 0;
        $loopReminderPeriod = $data->loopReminderPeriod ?? 'days';
        $loopReminder = $loopReminderValue. ' ' .$loopReminderPeriod;

        if(count($date) > 0 && $data->loopReminderPeriod)
        {
            $remindDate = $date[0];
            $remindDate->modify('-'.$loopReminder);
            return $remindDate;
        }

        return null;
    }


    // Private Methods
    // =========================================================================
    /**
     * @throws \Exception
     */
    private function _fetchDates($start, $startHour, $end, $period, $limit = 0, $futureDates = true)
    {
        $interval = $this->_calculateInterval($period);

        $today = new DateTime();

        $startDate = DateTimeHelper::toDateTime($start);
        $endDate = DateTimeHelper::toDateTime($end);

        if($startHour instanceof \DateTime) {
            $hours = $startHour->format('H');
            $minutes = $startHour->format('m');
            $startDate->setTime($hours,$minutes);
        }

        $interval = new DateInterval($interval);
        $arrDates = [];

        $period = new DatePeriod($startDate, $interval, $endDate);

        foreach ( $period as $date ) {

            // check if we have a timestring set ( ordinal/day )

            Craft::dd($period);

            // check if we have days selected

            // if the date is larger than today and only future dates are accepted, only fill the array.
            // Otherwise, if we don't have to check on future dates, add everything in it

            if($date > $today && $futureDates) {
                $arrDates[] = $date;
            }
            elseif(!$futureDates) {
                $arrDates[] = $date;
            }

            if ($limit > 0 && count($arrDates) >= $limit) {
                break;
            };
        }

        return $arrDates;
    }

    private function _calculateInterval($period)
    {

        switch($period->frequency) {
            case 'P1D':
                return 'P' . $period->cycle . 'D';

            case 'P1W':
                return 'P1W';

            case 'P1M':
                return 'P1M';

            case 'P1Y':
                return 'P' . $period->cycle . 'Y';

        }

    }
}
