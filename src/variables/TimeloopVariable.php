<?php

namespace percipiolondon\timeloop\variables;

use percipiolondon\timeloop\Timeloop;

class TimeloopVariable
{
    /**
     * Returns the first upcoming date from the timeloop date
     *
     */
    public function getUpcoming($data)
    {
        $upcoming = Timeloop::$plugin->timeloop->getLoop($data, 1);

        if(count($upcoming) > 0) {
            return $upcoming[0];
        }else {
            return false;
        }
    }

    /**
     * Returns the $limit upcoming dates from the timeloop start date
     *
     * @param array $data
     * @param bool $futureDates
     * @param integer $limit --> default = 0 = no limit is set
     *
     */
    public function getDates($data, $limit = 0, $futureDates = true)
    {
        return Timeloop::$plugin->timeloop->getLoop($data, $limit, $futureDates);
    }
}
