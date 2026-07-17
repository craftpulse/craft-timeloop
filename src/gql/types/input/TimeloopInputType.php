<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\gql\types\input;

use craft\gql\GqlEntityRegistry;
use craft\gql\types\DateTime;
use craftpulse\timeloop\fields\TimeloopField;
use GraphQL\Type\Definition\InputObjectType;

use GraphQL\Type\Definition\Type;

/**
 * Timeloop GraphQL mutation input type.
 *
 * @author CraftPulse
 * @since 4.0.0
 */
class TimeloopInputType extends InputObjectType
{
    // Static Methods
    // =========================================================================

    /**
     * Creates the mutation input type for a Timeloop field.
     *
     * @param TimeloopField $context
     * @return Type
     *
     * @author CraftPulse
     * @since 4.0.0
     */
    public static function getType(TimeloopField $context): Type
    {
        $typeName = $context->handle . '_TimeloopInput';
        $periodTypeName = 'periodInput';
        $timeStringTypeName = 'timestringInput';

        $timeStringInputType = GqlEntityRegistry::getOrCreate($timeStringTypeName, fn() => new InputObjectType([
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

        $loopPeriodInputType = GqlEntityRegistry::getOrCreate($periodTypeName, fn() => new InputObjectType([
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

        $holidaysInputType = GqlEntityRegistry::getOrCreate('holidaysInput', fn() => new InputObjectType([
            'name' => 'holidaysInput',
            'fields' => [
                'enabled' => [
                    'name' => 'enabled',
                    'type' => Type::boolean(),
                    'description' => 'Whether public holidays are excluded from the recurrence.',
                ],
                'country' => [
                    'name' => 'country',
                    'type' => Type::string(),
                    'description' => 'The two-letter holiday country code, e.g. "be".',
                ],
                'region' => [
                    'name' => 'region',
                    'type' => Type::string(),
                    'description' => 'The optional holiday region within the country.',
                ],
            ],
        ]));

        $inputType = GqlEntityRegistry::getOrCreate($typeName, fn() => new InputObjectType([
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
                // 5.1.0 v2-native input (additive). Providing `rrule` switches
                // the value to the v2 engine: `exdates`, `rdates`, `timezone`
                // and `holidays` are honoured alongside it. The legacy
                // `loopPeriod` shape keeps working unchanged (the normalizer
                // upgrades it); those v2 keys only take effect with `rrule`.
                'rrule' => [
                    'name' => 'rrule',
                    'type' => Type::string(),
                    'description' => 'A raw RFC 5545 RRULE string (without DTSTART), e.g. "FREQ=WEEKLY;BYDAY=MO". The start date/time come from loopStartDate/loopStartTime.',
                ],
                'timezone' => [
                    'name' => 'timezone',
                    'type' => Type::string(),
                    'description' => 'The IANA timezone the recurrence is stored and expanded in. Defaults to the system timezone.',
                ],
                'exdates' => [
                    'name' => 'exdates',
                    'type' => Type::listOf(Type::string()),
                    'description' => 'Static exclusion dates (Y-m-d) removed from the set.',
                ],
                'rdates' => [
                    'name' => 'rdates',
                    'type' => Type::listOf(Type::string()),
                    'description' => 'Extra one-off dates (Y-m-d) added to the set. RDATEs anchor to the series start time of day; they cannot carry a time of their own.',
                ],
                'holidays' => [
                    'name' => 'holidays',
                    'type' => $holidaysInputType,
                    'description' => 'Public-holiday exclusion configuration.',
                ],
            ],
        ]));

        return $inputType;
    }
}
