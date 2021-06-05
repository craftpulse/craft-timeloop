<?php

namespace percipiolondon\timeloop\services;

use percipiolondon\timeloop\models\TimeloopModel;
use percipiolondon\timeloop\models\TimeStringModel;
use percipiolondon\timeloop\models\PeriodModel;

use Craft;
use craft\base\Component;

use craft\helpers\DateTimeHelper;
use craft\helpers\Gql;
use craft\helpers\Json;

use DateInterval;
use DateTime;
use DatePeriod;


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
        return new PeriodModel($data->period);
    }

    /**
     * Returns the $limit upcoming dates from the timeloop start date
     *
     * @param TimeloopModel $data
     * @param bool $futureDates
     * @param integer $limit
     *
     */
    public function getLoop(TimeloopModel $data, Int $limit = 0, bool $futureDates = true)
    {
        //  get start date from data object
        //  $startUnix = strtotime($data['loopStartDate']['date']);

        // check if the end date is set in data object, otherwise use today + 20 years as default to get way ahead in the future
        $next = new DateTime();
        $end = $data->loopEndDate instanceof \DateTime ?
            $data->loopEndDate :
            $next->modify('+20 years');

        // get ISO 8601 from the repeater in data object
        // Parse our period object to fetch dates
        $period = new PeriodModel($data->period);
        $timestring = new TimeStringModel($period->timestring);

        // if no limit is set, use the default so we don't end up with high number arrays
        $limit = $limit === 0 ? self::MAX_ARRAY_ENTRIES : $limit;

        // check if repeater exist, throw exception is no value is added
        if(!$period) {
            throw new \yii\base\Exception( "There's no correct repeater value set. Use P1D / P1W / P1M / P1Y." );
        }

        // return the array with dates
        return $this->_fetchDates($data->loopStartDate, $end, $period, $timestring, $limit, $futureDates);
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

    /**
     * Returns an array with all the dates between a start and end point
     *
     * Returned data is based on the period entered
     *
     * @param DateTime $start
     * @param DateTime $end
     * @param PeriodModel $period
     * @param TimeStringModel $timestring
     * @param int $limit positive
     * @param Bool $futureDates
     *
     */
    private function _fetchDates(DateTime $start, DateTime $end, PeriodModel $period, TimeStringModel $timestring, Int $limit = 0, Bool $futureDates = true)
    {
        $interval = $this->_calculateInterval($period)[0]->interval;
        $frequency = $this->_calculateInterval($period)[0]->frequency;

        $today = new DateTime();

        $dateInterval = new DateInterval($interval);
        $arrDates = [];

        $datePeriod = new DatePeriod($start, $dateInterval, $end);

        $counter = 0;

        foreach ( $datePeriod as $date ) {

            // if the date is larger than today and only future dates are accepted, only fill the array.
            // Otherwise, if we don't have to check on future dates, add everything in it

            $dateToParse = $frequency === 'monthly' ? $start : $date;

            if ($date > $today && $futureDates) {

                $loopDates = $this->_parseDate($frequency, $dateToParse, $counter, $period, $timestring);

                if ( gettype($loopDates) === 'array' ) {
                    foreach ( $loopDates as &$loopDate) {
                        $arrDates[] = $loopDate;
                    }
                } else {
                    $arrDates[] = $loopDates;
                }

            } elseif (!$futureDates) {

                $loopDates = $this->_parseDate($frequency, $dateToParse, $counter, $period, $timestring);

                if ( gettype($loopDates) === 'array' ) {
                    foreach ( $loopDates as &$loopDate) {
                        $arrDates[] = $loopDate;
                    }
                } else {
                    $arrDates[] = $loopDates;
                }

            }

            if ($limit > 0 && count($arrDates) >= $limit) {
                break;
            };

            $counter++;
        }

        return $arrDates;
    }

    /**
     * Returns the $interval for the DatePeriod
     *
     * @param PeriodModel $period
     *
     */
    private function _calculateInterval(PeriodModel $period): array
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
     * Returns the $date with the month corrected for a monthly loop
     *
     * correctly calculates end of months when we shift to a shorter or longer month
     *
     * Shifting from the 28th Feb +1 month is 31st March
     * Shifting from the 28th Feb -1 month is 31st Jan
     * Shifting from the 29,30,31 Jan +1 month is 28th (or 29th) Feb
     *
     *
     * @param DateTime $date
     * @param int $months positive or negative
     * @param int $cycle positive
     *
     */

    private function _monthCorrection(DateTime $date, Int $months, Int $cycle): DateTime
    {

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

    /**
     * Returns the $date to add to the result could be DateTime or Array
     *
     * correctly calculates end of months when we shift to a shorter or longer month
     *
     *
     * @param String $frequency
     * @param DateTime $date
     * @param int $counter The loop Counter
     * @param PeriodModel $period
     * @param TimeStringModel $timestring
     *
     */
    private function _parseDate(String $frequency, DateTime $date, Int $counter, PeriodModel $period, TimeStringModel $timestring)
    {

        switch($frequency) {

            case 'daily':
            case 'yearly':
            default:

                $loopDate = $date;
                break;

            case 'weekly':

                $weekDates = [];
                $hours = $date->format('H');
                $minutes = $date->format('i');

                if (count($period->days) > 0) {
                    foreach ($period->days as $day) {
                        $weekDay = clone($date)->modify(strtolower($day) . ' this week')->setTime($hours, $minutes);
                        $weekDates[] = DateTimeHelper::toDateTime($weekDay);
                    }

                    $loopDate = $weekDates;
                } else {

                    $loopDate = $date;

                }


                break;

            case 'monthly':

                $monthlyDate = $this->_monthCorrection($date, $counter, $period->cycle);

                if ( $timestring->ordinal !== 'none' && $timestring->day !== 'none' ) {
                    // set to timestring variables else == $monthlyDate.
                    $loopDate = $monthlyDate->modify($timestring->ordinal . ' ' . $timestring->day . ' of this month');
                } else {
                    $loopDate = $monthlyDate;
                }

                break;

        }

        return gettype($loopDate) === 'array' ? $loopDate : DateTimeHelper::toDateTime($loopDate) ?? null;

    }
}
