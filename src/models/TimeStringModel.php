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

    const NOT_MONTHLY = 'Loop isn\'t monthly';

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

    public function init()
    {
        $this->ordinal = $this->ordinal ? $this->ordinal !== 'none' ?: null : self::NOT_MONTHLY;
        $this->day = $this->day ? $this->day !== 'none' ?: null : self::NOT_MONTHLY;
    }

}
