<?php

namespace percipioglobal\timeloop\variables;

use Craft;

class TimeloopVariable
{
    public function getUpcomingDate($data)
    {
        $startDate = strtotime($data['loopStart']['date']);

        switch ($data['repeat']) {
            case "day":
                return strtotime('tomorrow');
            case "week":
                return $this->_getNextWeek($startDate);
        }
        
        throw new \yii\base\Exception( "There's no repeatable date set into the field" );
    }

    private function _getNextWeek($unix)
    {
        $dayOfTheWeek = date("l", $unix);
        return strtotime('next ' . $dayOfTheWeek );
    }
}