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
use percipiolondon\timeloop\gql\types\input\TimeloopInputType;
/**use percipiolondon\timeloop\models\PeriodModel;*/

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\base\SortableFieldInterface;

use craft\gql\GqlEntityRegistry;
use craft\gql\TypeLoader;
use craft\gql\types\DateTime;

use craft\helpers\Db;
use craft\helpers\DateTimeHelper;
use craft\helpers\Gql;
use craft\helpers\Json;

use craft\i18n\Locale;

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
class TimeloopField extends Field implements PreviewableFieldInterface, SortableFieldInterface
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

    /**
     * @var bool Whether to show input sources for volumes the user doesn’t have permission to view.
     * @since 3.4.0
     */
    public $timeloopRequired = true;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['showTime'], 'boolean'];

        return $rules;
    }

    /**
     * @return string The column type. [[\yii\db\QueryBuilder::getColumnType()]] will be called
     * to convert the give column type to the physical one. For example, `string` will be converted
     * as `varchar(255)` and `string(100)` becomes `varchar(100)`. `not null` will automatically be
     * appended as well.
     * @see \yii\db\QueryBuilder::getColumnType()
     */
    public function getContentColumnType()
    {
        return Schema::TYPE_TEXT;
    }

    /**
     * @inheritdoc
     * @since 1.2.1
     */
    public function useFieldset(): bool
    {
        return true;
    }

    /**
     * @param mixed                 $value   The raw field value
     * @param ElementInterface|null $element The element the field is associated with, if there is one
     *
     * @return mixed The prepared field value
     */
    public function normalizeValue($value, ?\craft\base\ElementInterface $element = null)
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
    public function serializeValue($value, ?\craft\base\ElementInterface $element = null)
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

            $value['loopEndDate'] = Db::prepareDateForDb($value['loopEndDate']->setTime($hours ?? 23, $minutes ?? 59));
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
    public function getSettingsHtml(): ?string
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

        if(!$value->loopStartDate) {
            return '';
        }

        $upcoming = Timeloop::$plugin->timeloop->getLoop($value, 1);

        if ( count($upcoming) === 1 ) {
            return '<span> Next up: ' . Craft::$app->getFormatter()->asDate($upcoming[0], Locale::LENGTH_SHORT) . '</span>';
        } else {
            return '<span> Next up: None</span>';
        }

    }

    /**
     * @param mixed                 $value           The field’s value. This will either be the [[normalizeValue() normalized value]],
     *                                               raw POST data (i.e. if there was a validation error), or null
     * @param ElementInterface|null $element         The element the field is associated with, if there is one
     *
     * @return string The input HTML.
     */
    public function getInputHtml($value, ?\craft\base\ElementInterface $element = null): string
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
                'required' => $this->required,
                'id' => $id,
                'namespacedId' => $namespacedId,
                'settings' => $this->getSettings(),
            ]
        );
    }

    /**
     * @inheritdoc
    */
    public function isValueEmpty($value, ElementInterface $element): bool
    {

        if ( !$value->loopStartDate ) {
            return parent::isValueEmpty('', $element);
        }

        return false;

    }

    /**
     * @return array
     */
    public function getContentGqlType()
    {
        $typeName = $this->handle;

        $timestringType = GqlEntityRegistry::getEntity('timestring') ?: GqlEntityRegistry::createEntity('timestring', new ObjectType([
            'name' => 'timestring',
            'fields' => [
                'ordinal' => [
                    'name' => 'ordinal',
                    'type' => Type::string(),
                    'description' => 'The timestring ordinal',
                ],
                'day' => [
                    'name' => 'day',
                    'type' => Type::string(),
                    'description' => 'The timestring day',
                ],

            ]
        ]));

        $periodType = GqlEntityRegistry::getEntity('loopPeriod') ?: GqlEntityRegistry::createEntity('loopPeriod', new ObjectType([
            'name' => 'loopPeriod',
            'fields' => [
                'frequency' => [
                    'name' => 'frequency',
                    'type' => Type::string(),
                    'description' => 'The period frequency',
                ],
                'cycle' => [
                    'name' => 'cycle',
                    'type' => Type::int(),
                    'description' => 'The period cycle',
                ],
                'days' => [
                    'name' => 'days',
                    'type' => Type::ListOf(Type::string()),
                    'description' => 'Selected days of the week for the weekly frequency.',
                ],
                'timestring' => [
                    'name' => 'timestring',
                    'type' => $timestringType,
                    'description' => 'The selected timestring for the monthly frequency.'
                ]
            ]
        ]));

        $timeloopType = GqlEntityRegistry::getEntity($typeName) ?: GqlEntityRegistry::createEntity($typeName, new ObjectType([
            'name' => $typeName,
            'fields' => [
                'loopPeriod' => [
                    'name' => 'loopPeriod',
                    'type' => $periodType,
                    'description' => 'The loop period (daily / weekly / monthly / yearly)',
                ],
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
                        $return = Gql::applyDirectives($source, $resolveInfo, $value);
                        return $return ? $return : null;
                    }
                ],
                'loopStartTime' => [
                    'name' => 'loopStartTime',
                    'type' => DateTime::getType(),
                    'description' => 'The start hour of the loop',
                    'resolve' => function ($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $fieldName = $resolveInfo->fieldName;
                        $value = DateTimeHelper::toDateTime($source[$fieldName]);
                        return  $value ? $value->format('H:i') : null;
                    }
                ],
                'loopEndDate' => [
                    'name' => 'loopEndDate',
                    'type' => DateTime::getType(),
                    'description' => 'The end date of the loop',
                    'resolve' => function ($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $fieldName = $resolveInfo->fieldName;
                        $value = DateTimeHelper::toDateTime($source[$fieldName]);
                        $return = Gql::applyDirectives($source, $resolveInfo, $value);
                        return $return ? $return : null;
                    }
                ],
                'loopEndTime' => [
                    'name' => 'loopEndTime',
                    'type' => DateTime::getType(),
                    'description' => 'The end hour of the loop',
                    'resolve' => function ($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $fieldName = $resolveInfo->fieldName;
                        $value = DateTimeHelper::toDateTime($source[$fieldName]);
                        return  $value ? $value->format('H:i') : null;
                    }
                ],
                'getReminder' => [
                    'name' => 'getReminder',
                    'type' => DateTime::getType(),
                    'resolve' => function ($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $reminder = Timeloop::$plugin->timeloop->getReminder($source);

                        return false == $reminder ? null : Gql::applyDirectives($source, $resolveInfo, $reminder);
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

                        if ( $dates ) {

                            foreach ($dates as &$date) {
                                $date = Gql::applyDirectives($source, $resolveInfo, DateTimeHelper::toDateTime($date));
                            }

                            return $dates;
                        }

                        return null;
                    }
                ],
                'getUpcoming' => [
                    'name' => 'getUpcoming',
                    'type' => DateTime::getType(),
                    'resolve' => function ($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $upcoming = Timeloop::$plugin->timeloop->getLoop($source, 1);

                        if( $upcoming ) {
                            if(count($upcoming) > 0){
                                return  Gql::applyDirectives($source, $resolveInfo, DateTimeHelper::toDateTime($upcoming[0]));
                            }
                        }

                        return null;
                    }
                ],
            ]
        ]));

        TypeLoader::registerType($typeName, static function() use ($timeloopType) {
            return $timeloopType;
        });

        return $timeloopType;
    }

    public function getContentGqlMutationArgumentType()
    {
        return TimeloopInputType::getType($this);
    }

}


