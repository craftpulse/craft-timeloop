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
        return Timeloop::$plugin->timeloop->getUpcoming($data);
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
