<?php

namespace percipiolondon\timeloop\variables;

use craft\helpers\Json;

use nystudio107\pluginvite\variables\ViteVariableInterface;
use nystudio107\pluginvite\variables\ViteVariableTrait;

use percipiolondon\timeloop\Timeloop;

/**
 * @author    percipiolondon
 * @package   Timeloop
 * @since     1.0.0
 */

class TimeloopVariable implements ViteVariableInterface
{
    use ViteVariableTrait;


    /**
     * Returns just the JSON code
     */
    public function period($data)
    {
        return Timeloop::$plugin->timeloop->showPeriod($data);
    }

    /**
     * Returns the first upcoming date from the timeloop date
     * @param array $data
     *
     */
    public function getUpcoming($data)
    {
        $upcoming = Timeloop::$plugin->timeloop->getLoop($data, 1);

        if (count($upcoming) > 0) {
            return $upcoming[0];
        } else {
            return false;
        }
    }
    /**
     * Returns the first upcoming reminder date from the timeloop date
     * @param array $data
     *
     */
    public function getReminder($data)
    {
        return Timeloop::$plugin->timeloop->getReminder($data, 1);
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
