<?php

namespace percipiolondon\timeloop\services;

use Craft;
use craft\base\Component;
use craft\helpers\Gql;
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
        return $data['loopPeriod'];
    }

    /**
     * Returns the $limit upcoming dates from the timeloop start date
     *
     * @param array $data
     * @param bool $futureDates
     * @param integer $limit
     *
     */
    public function getLoop(array $data, $limit = 0, bool $futureDates = true)
    {
        //  get start date from data object
        //  $startUnix = strtotime($data['loopStart']['date']);

        //check if the end date is set in data object, otherwise use today + 20 years as default to get way ahead in the future
        $next = new DateTime();
        $end = $data['loopEnd'] instanceof \DateTime ?
            $data['loopEnd']:
            $next->modify('+20 years');

        // get ISO 8601 from the repeater in data object
        $repeater = $data['loopPeriod']['frequency'] ?? false;

        // if no limit is set, use the default so we don't end up with high number arrays
        $limit = $limit == 0 ? self::MAX_ARRAY_ENTRIES : $limit;

        // check if repeater exist, throw exception is no value is added
        if(!$repeater) {
            throw new \yii\base\Exception( "There's no correct repeater value set. Use daily / weekly / monthly / yearly." );
        }

        // return the array with dates
        return $this->_fetchDates($data['loopStart'], $data['loopStartHour'], $end, $repeater, $limit, $futureDates);
    }

    public function getReminder(array $data)
    {
        $date = $this->getLoop($data, 1);
        $loopReminderValue = $data['loopReminderValue'] ?? 0;
        $loopReminderPeriod = $data['loopReminderPeriod'] ?? 'days';
        $loopReminder = $loopReminderValue. ' ' .$loopReminderPeriod;

        if(count($date) > 0 && $data['loopReminderPeriod'])
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
    private function _fetchDates($start, $startHour, $end, $interval, $limit = 0, $futureDates = true)
    {
        $today = new DateTime();

        $startDate = craft\helpers\DateTimeHelper::toDateTime($start);
        $endDate = craft\helpers\DateTimeHelper::toDateTime($end);

        $interval = new DateInterval($interval);
        $arrDates = [];

        $period = new DatePeriod($startDate, $interval, $endDate);

        foreach ( $period as $date ) {

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
}
