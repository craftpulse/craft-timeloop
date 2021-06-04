<?php

namespace percipiolondon\timeloop\models;

use Craft;
use craft\base\Model;

/**
 * @author    percipiolondon
 * @package   Timeloop
 * @since     1.0.0
 */

class TimeStringModel extends Model
{

    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public $ordinal;

    /**
     * @var string
     */
    public $day;

    // Public Methods
    // =========================================================================

    public function rules()
    {
        return [
            ['ordinal', 'string'],
            ['day', 'array'],
        ];
    }

    public function getOrdinal()
    {

        return $this->ordinal !== 'none' ?: null;

    }

    public function getDay()
    {

        return $this->ordinal !== 'none' ?: null;

    }

}
