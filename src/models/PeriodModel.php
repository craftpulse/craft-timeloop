<?php

namespace percipiolondon\timeloop\models;

use craft\base\Model;

/**
 * @author    percipiolondon
 * @package   Timeloop
 * @since     1.0.0
 */

class PeriodModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public $frequency;

    /**
     * @var integer
     */
    public $cycle;

    /**
     * @var array
     */
    public $days;

    /**
     * @var array
     */
    public $timestring;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules = array_merge($rules,
            [['frequency'], 'string'],
            [['days', 'timestring'], 'array'],
            [['cycle'], 'number']
        );

        return $rules;
    }
}
