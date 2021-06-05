<?php
/**
 * Timeloop plugin for Craft CMS 3.x
 *
 * This is a plugin to make repeating dates
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2021 percipiolondon
 */

namespace percipiolondon\timeloop\fields;

use percipiolondon\timeloop\Timeloop;
use percipiolondon\timeloop\assetbundles\timeloop\TimeloopAsset;
use percipiolondon\timeloop\models\TimeloopModel;
use percipiolondon\timeloop\models\PeriodModel;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;

use craft\gql\GqlEntityRegistry;
use craft\gql\TypeLoader;
use craft\gql\types\DateTime;

use craft\helpers\Db;
use craft\helpers\DateTimeHelper;
use craft\helpers\Gql;
use craft\helpers\Json;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

use yii\base\BaseObject;
use yii\db\Schema;

/**
 * Timeloop Field
 *
 * Whenever someone creates a new field in Craft, they must specify what
 * type of field it is. The system comes with a handful of field types baked in,
 * and we’ve made it extremely easy for plugins to add new ones.
 *
 * https://craftcms.com/docs/plugins/field-types
 *
 * @author    percipiolondon
 * @package   Timeloop
 * @since     0.1.0
 */
class TimeloopField extends Field implements PreviewableFieldInterface
{
    // Public Properties
    // =========================================================================
    public $showTime = 0;

    // Static Methods
    // =========================================================================

    /**
     * Returns the display name of this class.
     *
     * @return string The display name of this class.
     */
    public static function displayName(): string
    {
        return Craft::t('timeloop', 'Timeloop');
    }

    // Public Methods
    // =========================================================================

    /**
     * @return array
     */
    public function rules()
    {
        $rules = parent::rules();
        $rules = array_merge($rules, [
            ['showTime', 'boolean'],
        ]);

        return $rules;
    }

    /**
     * @return string The column type. [[\yii\db\QueryBuilder::getColumnType()]] will be called
     * to convert the give column type to the physical one. For example, `string` will be converted
     * as `varchar(255)` and `string(100)` becomes `varchar(100)`. `not null` will automatically be
     * appended as well.
     * @see \yii\db\QueryBuilder::getColumnType()
     */
    public function getContentColumnType(): string
    {
        return \yii\db\Schema::TYPE_TEXT;
    }

    /**
     * @param mixed                 $value   The raw field value
     * @param ElementInterface|null $element The element the field is associated with, if there is one
     *
     * @return mixed The prepared field value
     */
    public function normalizeValue($value, ElementInterface $element = null)
    {

        if (is_string($value) && !empty($value)) {
            $value = Json::decode($value);
        }

        if (isset($value['loopStartDate']) ) {
            $value['loopStartDate'] = DateTimeHelper::toDateTime($value['loopStartDate']);
        }

        if (isset($value['loopEndDate']) ) {
            $value['loopEndDate'] = DateTimeHelper::toDateTime($value['loopEndDate']);
        }

        if (isset($value['loopStartTime']) ) {
            $value['loopStartTime'] = DateTimeHelper::toDateTime($value['loopStartTime']);
        }

        if (isset($value['loopEndTime']) ) {
            $value['loopEndTime'] = DateTimeHelper::toDateTime($value['loopEndTime']);
        }

        if (isset($value['loopPeriod'])) {
            $value['loopPeriod'] = Json::decodeIfJson($value['loopPeriod']);
        }

        $model = new TimeloopModel($value);

        return $model;

    }

    /**
     * @param mixed $value The raw field value
     * @param ElementInterface|null $element The element the field is associated with, if there is one
     * @return mixed The serialized field value
     */
    public function serializeValue($value, ElementInterface $element = null)
    {

        if (isset($value['loopStartDate']) && $value['loopStartDate'] instanceof \DateTime) {

            $hours = null;
            $minutes = null;

            if (isset($value['loopStartTime']) && $value['loopStartTime'] instanceof \DateTime) {
                $hours = $value['loopStartTime']->format('H');
                $minutes = $value['loopStartTime']->format('i');
            }

            $value['loopStartDate'] = Db::prepareDateForDb($value['loopStartDate']->setTime($hours ?? 0, $minutes ?? 0));
        }

        if (isset($value['loopEndDate']) && $value['loopEndDate'] instanceof \DateTime) {

            $hours = null;
            $minutes = null;

            if (isset($value['loopEndTime']) && $value['loopEndTime'] instanceof \DateTime) {
                $hours = $value['loopEndTime']->format('H');
                $minutes = $value['loopEndTime']->format('i');
            }

            $value['loopEndDate'] = Db::prepareDateForDb($value['loopEndDate']->setTime($hours ?? 0, $minutes ?? 0));
        }

        if (isset($value['loopStartTime']) && $value['loopStartTime'] instanceof \DateTime) {
            $value['loopStartTime'] = Db::prepareDateForDb($value['loopStartTime']);
        }

        if (isset($value['loopEndTime']) && $value['loopEndTime'] instanceof \DateTime) {
            $value['loopEndTime'] = Db::prepareDateForDb($value['loopEndTime']);
        }

        if( isset($value['loopReminderPeriod']) && '' === $value['loopReminderPeriod']) {
            $value['loopReminderValue'] = 0;
        }

        if( is_string($value) && !empty($value['loopPeriod'])) {
            $value['loopPeriod'] = Json::encode($value['loopPeriod']);
        }

        return parent::serializeValue($value, $element);
    }

    /**
     * @return string|null
     */
    public function getSettingsHtml()
    {

        // Render the settings template
        return Craft::$app->getView()->renderTemplate(
            'timeloop/fields/timeloop-settings',
            [
                'settings' => $this->getSettings()
            ]
        );
    }

    /**
     * @param mixed $value
     * @param ElementInterface $element
     * @return string
     */
    public function getTableAttributeHtml($value, ElementInterface $element): string
    {
        if($value) {
            $upcoming = Timeloop::$plugin->timeloop->getLoop($value, 1)[0];
            $upcoming = false == $upcoming ? 'No upcoming dates' : ($this->getSettings()['showTime'] ? $upcoming->format('d-m-y g:ia') : $upcoming->format('d-m-y'));
            return '<div>Next up: ' . $upcoming . '</div>';
        }

        return '-';
    }

    /**
     * @param mixed                 $value           The field’s value. This will either be the [[normalizeValue() normalized value]],
     *                                               raw POST data (i.e. if there was a validation error), or null
     * @param ElementInterface|null $element         The element the field is associated with, if there is one
     *
     * @return string The input HTML.
     */
    public function getInputHtml($value, ElementInterface $element = null): string
    {
        // Register our asset bundle
        Craft::$app->getView()->registerAssetBundle(TimeloopAsset::class);

        // Get our id and namespace
        $id = Craft::$app->getView()->formatInputId($this->handle);
        $namespacedId = Craft::$app->getView()->namespaceInputId($id);

        // Variables to pass down to our field JavaScript to let it namespace properly
        $jsonVars = [
            'id' => $id,
            'name' => $this->handle,
            'namespace' => $namespacedId,
            'prefix' => Craft::$app->getView()->namespaceInputId(''),
        ];
        $jsonVars = Json::encode($jsonVars);
        Craft::$app->getView()->registerJs("$('#{$namespacedId}-field').TimeloopTimeloop(" . $jsonVars . ");");

        // Render the input template
        return Craft::$app->getView()->renderTemplate(
            'timeloop/fields/timeloop-input',
            [
                'name' => $this->handle,
                'value' => $value,
                'field' => $this,
                'id' => $id,
                'namespacedId' => $namespacedId,
                'settings' => $this->getSettings(),
            ]
        );
    }

    /**
     * @return array
     */
    public function getContentGqlType()
    {
        $typeName = $this->handle;

        $timeloopType = GqlEntityRegistry::getEntity($typeName) ?: GqlEntityRegistry::createEntity($typeName, new ObjectType([
            'name' => $typeName,
            'fields' => [
                //'loopPeriod' => [
                //    'name' => 'loopPeriod',
                //    'type' => Type::ListOf(DateTime::getType()),
                //    'description' => 'The loop period (daily / weekly / monthly / yearly)',
                //    'resolve' => function ($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                //        $fieldName = $resolveInfo->fieldName;
                //        $value = new PeriodModel($source[$fieldName]);
                //        $period = [
                //            'frequency' => $value->frequency,
                //            'cycle' => $value->cycle,
                //            'days' => $value->days,
                //            'timestring' => $value->timestring,
                //        ];
                //
                //        return $period;
                //   }
                //],
                'loopReminder' => [
                    'name' => 'loopReminder',
                    'type' => Type::string(),
                    'description' => 'The loop reminder period'
                ],
                'loopStartDate' => [
                    'name' => 'loopStartDate',
                    'type' => DateTime::getType(),
                    'description' => 'The start date of the loop',
                    'resolve' => function ($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $fieldName = $resolveInfo->fieldName;
                        $value = DateTimeHelper::toDateTime($source[$fieldName]);
                        return Gql::applyDirectives($source, $resolveInfo, $value);
                    }
                ],
                'loopStartTime' => [
                    'name' => 'loopStartTime',
                    'type' => DateTime::getType(),
                    'description' => 'The start hour of the loop',
                    'resolve' => function ($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $fieldName = $resolveInfo->fieldName;
                        $value = DateTimeHelper::toDateTime($source[$fieldName]);
                        return  $value ? $value->format('H:i') : false;
                    }
                ],
                'loopEndDate' => [
                    'name' => 'loopEndDate',
                    'type' => DateTime::getType(),
                    'description' => 'The end date of the loop',
                    'resolve' => function ($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $fieldName = $resolveInfo->fieldName;
                        $value = DateTimeHelper::toDateTime($source[$fieldName]);
                        return Gql::applyDirectives($source, $resolveInfo, $value);
                    }
                ],
                'loopEndTime' => [
                    'name' => 'loopEndTime',
                    'type' => DateTime::getType(),
                    'description' => 'The end hour of the loop',
                    'resolve' => function ($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $fieldName = $resolveInfo->fieldName;
                        $value = DateTimeHelper::toDateTime($source[$fieldName]);
                        return  $value ? $value->format('H:i') : false;
                    }
                ],
                'getReminder' => [
                    'name' => 'getReminder',
                    'type' => DateTime::getType(),
                    'resolve' => function ($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $reminder = Timeloop::$plugin->timeloop->getReminder($source);

                        return false == $reminder ? $reminder : Gql::applyDirectives($source, $resolveInfo, $reminder);
                    }
                ],
                'getDates' => [
                    'name' =>'getDates',
                    'type' => Type::listOf(DateTime::getType()),
                    'args' => [
                        'limit' => [
                            'type' => Type::int(),
                            'name' => "limit",
                            'description' => "Limit how many dates you want in return. By default it returns 100 dates"
                        ],
                        'futureDates' => [
                            'type' => Type::boolean(),
                            'name' => "futureDates",
                            'description' => "Set to false if you want to dates from the start date. By default it returns only future dates"
                        ]
                    ],
                    'resolve' => function ($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $dates = Timeloop::$plugin->timeloop->getLoop($source, $arguments['limit'] ?? 0, $arguments['futureDates'] ?? true);

                        foreach ($dates as &$date) {
                            $date = Gql::applyDirectives($source, $resolveInfo, DateTimeHelper::toDateTime($date));
                        }

                        return $dates;
                    }
                ],
                'getUpcoming' => [
                    'name' => 'getUpcoming',
                    'type' => DateTime::getType(),
                    'resolve' => function ($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $upcoming = Timeloop::$plugin->timeloop->getLoop($source, 1);

                        if(count($upcoming) > 0){
                            return  Gql::applyDirectives($source, $resolveInfo, DateTimeHelper::toDateTime($upcoming[0]));
                        }

                        return false;
                    }
                ],
            ]
        ]));

        TypeLoader::registerType($typeName, static function() use ($timeloopType) {
            return $timeloopType;
        });

        return $timeloopType;
    }
}


