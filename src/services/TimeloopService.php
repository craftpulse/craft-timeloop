<?php

namespace percipiolondon\timeloop\services;

use percipiolondon\timeloop\models\TimeloopModel;

use Craft;
use craft\base\Component;

use craft\helpers\DateTimeHelper;
use craft\helpers\Gql;
use craft\helpers\Json;

use DateInterval;
use DateTime;
use DatePeriod;
use DateTimeZone;


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
            $data['loopEnd'] :
            $next->modify('+20 years');

        // get ISO 8601 from the repeater in data object
        // Parse our period object to fetch dates
        $repeater = $data->period ?? false;

        // if no limit is set, use the default so we don't end up with high number arrays
        $limit = $limit === 0 ? self::MAX_ARRAY_ENTRIES : $limit;

        // check if repeater exist, throw exception is no value is added
        if(!$repeater) {
            throw new \yii\base\Exception( "There's no correct repeater value set. Use P1D / P1W / P1M / P1Y." );
        }

        // return the array with dates
        return $this->_fetchDates($data->loopStart, $end, $repeater, $limit, $futureDates);

        //Craft::dd($this->_fetchDates($data->loopStart, $end, $repeater, $limit, $futureDates));
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
    private function _fetchDates($start, $end, $period, $limit = 0, $futureDates = true)
    {
        $interval = $this->_calculateInterval($period)[0]->interval;
        $frequency = $this->_calculateInterval($period)[0]->frequency;
        $cycle = $period->cycle;

        $startDate = DateTimeHelper::toDateTime($start);
        $endDate = DateTimeHelper::toDateTime($end);

        $today = new DateTime();

        $dateInterval = new DateInterval($interval);
        $arrDates = [];

        $datePeriod = new DatePeriod($startDate, $dateInterval, $endDate);

        $counter = 0;

        foreach ( $datePeriod as $date ) {

            // check if we have a timestring set ( ordinal/day )

            // check if we have days selected

            // if the date is larger than today and only future dates are accepted, only fill the array.
            // Otherwise, if we don't have to check on future dates, add everything in it

            if ($date > $today && $futureDates) {

                $arrDates[] = $this->_parseDate($frequency, $start, $counter, $cycle);

            } elseif (!$futureDates) {

                $arrDates[] = $this->_parseDate($frequency, $start, $counter, $cycle);

            }

            if ($limit > 0 && count($arrDates) >= $limit) {
                break;
            };

            $counter++;
        }

        return $arrDates;
    }

    private function _calculateInterval($period)
    {

        $frequency = [];

        switch($period->frequency) {
            case 'P1D':
                $frequency[] = (object) [
                    'interval' => 'P' . $period->cycle . 'D',
                    'frequency' => 'daily',
                ];

                break;

            case 'P1W':

                $frequency[] = (object) [
                    'interval' => 'P' . $period->cycle . 'W' ,
                    'frequency' => 'weekly',
                ];

                break;

            case 'P1M':

                $frequency[] = (object) [
                    'interval' => 'P' . $period->cycle . 'M',
                    'frequency' => 'monthly',
                ];

                break;

            case 'P1Y':

                $frequency[] = (object) [
                    'interval' => 'P' . $period->cycle . 'Y',
                    'frequency' => 'yearly',
                ];

                break;
        }

        return $frequency;

    }

    /**
     * correctly calculates end of months when we shift to a shorter or longer month
     *
     * Shifting from the 28th Feb +1 month is 31st March
     * Shifting from the 28th Feb -1 month is 31st Jan
     * Shifting from the 29,30,31 Jan +1 month is 28th (or 29th) Feb
     *
     *
     * @param DateTime $date
     * @param int $months positive or negative
     *
     */

    private function _monthCorrection(DateTime $date, Int $months, Int $cycle) {

        $frequency = $months * $cycle;

        // making 2 clones of our dates to be able to do calculations
        $date1 = clone($date);
        $date2 = clone($date);

        $addedMonths = clone($date1->modify($frequency . ' Month'));


        if( $date2 != $date1->modify($frequency*-1 . ' Month') ) {

            $result = $addedMonths->modify('last day of last month');

        } elseif ( $date == $date2->modify('last day of this month')) {

            $result = $addedMonths->modify('last day of this month');

        } else {

            $result = $addedMonths;

        }

        return $result;
    }

    private function _parseDate(String $frequency, DateTime $date, Int $counter, Int $cycle) {

        switch($frequency) {

            case 'monthly':

                $loopDate = $this->_monthCorrection($date, $counter, $cycle);

        }

        return DateTimeHelper::toDateTime($loopDate);

    }
}
