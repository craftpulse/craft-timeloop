<?php

namespace percipiolondon\timeloop\variables;

use craft\helpers\Json;
use nystudio107\pluginvite\variables\ViteVariableInterface;
use nystudio107\pluginvite\variables\ViteVariableTrait;
use percipiolondon\timeloop\models\TimeloopModel;
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
     *
     * @param TimeloopModel $data
     */
    public function period(TimeloopModel $data)
    {
        return Timeloop::$plugin->timeloop->showPeriod($data);
    }

    /**
     * Returns the first upcoming date from the timeloop date
     * @param TimeloopModel $data
     *
     */
    public function getUpcoming(TimeloopModel $data)
    {
        $upcoming = Timeloop::$plugin->timeloop->getLoop($data, 1);

        return $upcoming !== [] ? $upcoming[0] : false;
    }
    /**
     * Returns the first upcoming reminder date from the timeloop date
     * @param TimeloopModel $data
     *
     */
    public function getReminder(TimeloopModel $data)
    {
        return Timeloop::$plugin->timeloop->getReminder($data);
    }

    /**
     * Returns the $limit upcoming dates from the timeloop start date
     *
     * @param TimeloopModel $data
     * @param bool $futureDates
     * @param integer $limit --> default = 0 = no limit is set
     *
     */
    public function getDates(TimeloopModel $data, int $limit = 0, bool $futureDates = true)
    {
        return Timeloop::$plugin->timeloop->getLoop($data, $limit, $futureDates);
    }
}
