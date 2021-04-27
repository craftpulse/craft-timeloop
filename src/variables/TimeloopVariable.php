<?php

namespace percipioglobal\timeloop\variables;

use percipioglobal\timeloop\Timeloop;

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

    public function getDates($data, $limit = null)
    {
        return Timeloop::$plugin->timeloop->getLoop($data, $limit);
    }
}
