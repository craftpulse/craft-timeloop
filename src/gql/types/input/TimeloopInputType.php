<?php
/**
 * Timeloop plugin for Craft CMS 3.x
 *
 * This is a plugin to make repeating dates
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2021 percipiolondon
 */

namespace percipiolondon\timeloop\gql\types\input;

use craft\gql\GqlEntityRegistry;
use craft\gql\types\DateTime;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

use percipiolondon\timeloop\fields\TimeloopField;

/**
 * Class Timeloop
 */

class TimeloopInputType extends InputObjectType
{
    /**
     * Create the type for a timeloop field.
     */

    public static function getType(TimeloopField $context)
    {
        /** @var TimeloopField $context */
        $typeName = $context->handle . '_TimeloopInput';
        $periodTypeName = 'periodInput';
        $timeStringTypeName = 'timestringInput';

        $timeStringInputType = GqlEntityRegistry::getEntity($timeStringTypeName) ?: GqlEntityRegistry::createEntity($timeStringTypeName, new InputObjectType([
            'name' => $timeStringTypeName,
            'fields' => [
                'ordinal' => [
                    'name' => 'ordinal',
                    'type' => Type::string(),
                    'description' => 'The time string ordinal, supported are: First, Second, Third, Fourth, Last',
                ],
                'day' => [
                    'name' => 'day',
                    'type' => Type::string(),
                    'description' => 'The time string day as a string e.g. Monday',
                ],
            ],
        ]));

        $loopPeriodInputType = GqlEntityRegistry::getEntity($periodTypeName) ?: GqlEntityRegistry::createEntity($periodTypeName, new InputObjectType([
            'name' => $periodTypeName,
            'fields' => [
                'frequency' => [
                    'name' => 'frequency',
                    'type' => Type::string(),
                    'description' => 'The period frequency ( P1D, P1W, P1M, P1Y )',
                ],
                'cycle' => [
                    'name' => 'cycle',
                    'type' => Type::int(),
                    'description' => 'The period cycle',
                ],
                'days' => [
                    'name' => 'days',
                    'type' => Type::ListOf(Type::string()),
                    'description' => 'Selected days of the week for the weekly frequency, array of days as string e.g. [\'Monday\', \'Friday\']',
                ],
                'timestring' => [
                    'name' => 'timestring',
                    'type' => $timeStringInputType,
                ],
            ],
        ]));

        $inputType = GqlEntityRegistry::getEntity($typeName) ?: GqlEntityRegistry::createEntity($typeName, new InputObjectType([
            'name' => $typeName,
            'fields' => [
                'loopStartDate' => [
                    'name' => 'loopStartDate',
                    'type' => DateTime::getType(),
                ],
                'loopEndDate' => [
                    'name' => 'loopEndDate',
                    'type' => DateTime::getType(),
                ],
                'loopStartTime' => [
                    'name' => 'loopStartTime',
                    'type' => DateTime::getType(),
                ],
                'loopEndTime' => [
                    'name' => 'loopEndTime',
                    'type' => DateTime::getType(),
                ],
                'loopPeriod' => [
                    'name' => 'loopPeriod',
                    'type' => $loopPeriodInputType,
                ],
            ],
        ]));

        return $inputType;
    }
}
