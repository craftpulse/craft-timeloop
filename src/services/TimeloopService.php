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
        $startUnix = strtotime($data['loopStart']['date']);
        $today = strtotime('today');
        $repeater = self::REPEAT_PATTERN[$data['repeat']] ?? false;

        if(!$repeater) {
            throw new \yii\base\Exception( "There's no correct repeater value set. Use daily / weekly / monthly / yearly." );
        }

        return $this->_getLoops($startUnix, $today, $repeater)[0];

        throw new \yii\base\Exception( "There's no repeatable date set into the field" );
    }

    /**
     * Returns the 100 upcoming dates from the timeloop start date
     *
     * @param array $data
     *
     */
    public function getLoop(array $data, $limit = null)
    {
        $startUnix = $this->getUpcoming($data)->getTimestamp();
        $end = array_key_exists('loopEnd', $data) && "" !== $data['loopEnd']['date'] ?
            strtotime($data['loopEnd']['date'] ):
            strtotime('+20 years');
        $repeater = self::REPEAT_PATTERN[$data['repeat']] ?? false;
        $limit = $limit ?? self::MAX_ARRAY_ENTRIES;

        if(!$repeater) {
            throw new \yii\base\Exception( "There's no correct repeater value set. Use daily / weekly / monthly / yearly." );
        }

        return $this->_getLoops($startUnix, $end, $repeater, $limit);

        throw new \yii\base\Exception( "There's no repeatable date set into the field" );

    }


    // Private Methods
    // =========================================================================
    /**
     * @throws \Exception
     */
    private function _getLoops($start, $end, $interval, $limit = self::MAX_ARRAY_ENTRIES)
    {
        $startDate = new DateTime();
        $startDate->setTimestamp($start);

        $endDate = new DateTime();
        $endDate = $endDate->setTimestamp($end);

        $interval = new DateInterval($interval);
        $arrDates = [];

        $period = new DatePeriod($startDate, $interval, $endDate);
        foreach ( $period as $date ) {
            $arrDates[] = $date;

            if (count($arrDates) >= $limit) {
                break;
            };
        }

        return $arrDates;
    }
}
