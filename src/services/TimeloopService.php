<?php

namespace percipioglobal\timeloop\services;

use Craft;
use craft\base\Component;
use DateInterval;
use DateTime;
use DatePeriod;

/**
 * Class TimeloopService
 * @package percipioglobal\timeloop\services
 */
class TimeloopService extends Component
{
    const MAX_ARRAY_ENTRIES = 100;
    const REPEAT_PATTERN = [
        "daily" => "P1D",
        "weekly" => "P1W",
        "monthly" => "P1M",
        "yearly" => "P1Y",
    ];
    const TIME_ADDITION = [
        "daily" => "+7 days",
        "weekly" => "+1 week",
        "monthly" => "+1 month",
        "yearly" => "+1 year",
    ];

    // Public Methods
    // =========================================================================

    /**
     * Returns the first upcoming date from the timeloop start date
     *
     * @param array $data
     *
     */
    public function getUpcoming(array $data)
    {
        //get start date from data object
        $startUnix = strtotime($data['loopStart']['date']);

        //get ISO 8601 from the repeater in data object
        $repeater = self::REPEAT_PATTERN[$data['repeat']] ?? false;

        //get today + repeater value from now to get the next date. We need a date in the future, otherwise it will return todays date.
        $next = strtotime(self::TIME_ADDITION[$data['repeat']] ?? 'today');

        //check if the end date is set in data object, otherwise use today + 1 day as default. By default the loop will not end with this date
        $end = array_key_exists('loopEnd', $data) && "" !== $data['loopEnd']['date'] ?
            strtotime($data['loopEnd']['date'] ):
            strtotime('+1 day');

        // check if repeater exist, throw exception is no value is added
        if(!$repeater) {
            throw new \yii\base\Exception( "There's no correct repeater value set. Use daily / weekly / monthly / yearly." );
        }

        //check if the end date is reached. If so, we return the function
        if(strtotime('today') >= $end) {
            return false;
        }

        //fetch all the dates and return the last one, because that's the first upcoming. Since we're using the today + repeater value
        $dates = $this->_getLoops($startUnix, $next, $repeater);
        return end($dates);
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
        //get start date from data object
        $startUnix = strtotime($data['loopStart']['date']);

        //check if the end date is set in data object, otherwise use today + 20 years as default to get way ahead in the future
        $end = array_key_exists('loopEnd', $data) && "" !== $data['loopEnd']['date'] ?
            strtotime($data['loopEnd']['date'] ):
            strtotime('+20 years');

        //get ISO 8601 from the repeater in data object
        $repeater = self::REPEAT_PATTERN[$data['repeat']] ?? false;

        //if no limit is set, use the default so we don't end up with high number arrays
        $limit = $limit == 0 ? self::MAX_ARRAY_ENTRIES : $limit;

        // check if repeater exist, throw exception is no value is added
        if(!$repeater) {
            throw new \yii\base\Exception( "There's no correct repeater value set. Use daily / weekly / monthly / yearly." );
        }

        // return the array with dates
        return $this->_getLoops($startUnix, $end, $repeater, $limit, $futureDates);
    }


    // Private Methods
    // =========================================================================
    /**
     * @throws \Exception
     */
    private function _getLoops($start, $end, $interval, $limit = 0, $futureDates = true)
    {
        $today = new DateTime();

        $startDate = new DateTime();
        $startDate->setTimestamp($start);

        $endDate = new DateTime();
        $endDate = $endDate->setTimestamp($end);

        $interval = new DateInterval($interval);
        $arrDates = [];

        $period = new DatePeriod($startDate, $interval, $endDate);

        foreach ( $period as $date ) {

            //if the date is larger than today and only future dates are accepted, only fill the array.
            //Otherwise, if we don't have to check on future dates, add everything in it
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
