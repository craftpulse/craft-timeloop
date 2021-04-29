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

use craft\gql\GqlEntityRegistry;
use craft\gql\TypeLoader;
use craft\gql\types\DateTime;
use craft\helpers\Gql;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use percipiolondon\timeloop\assetbundles\timeloopfield\TimeloopFieldAsset;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use percipiolondon\timeloop\Timeloop;
use yii\db\Schema;
use craft\helpers\Json;

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
class TimeloopField extends Field
{
    // Public Properties
    // =========================================================================


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
        return Schema::TYPE_STRING;
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
            $value = rtrim($value);
            $value = Json::decode($value);
        }

        return $value;
    }

    /**
     * @param mixed $value The raw field value
     * @param ElementInterface|null $element The element the field is associated with, if there is one
     * @return mixed The serialized field value
     */
    public function serializeValue($value, ElementInterface $element = null)
    {
        return parent::serializeValue($value, $element);
    }

    /**
     * @return string|null
     */
    public function getSettingsHtml()
    {
        // Render the settings template
//         return Craft::$app->getView()->renderTemplate(
//             'timeloop/_components/fields/Timeloop_settings',
//             [
//                 'field' => $this,
//             ]
//         );
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
        Craft::$app->getView()->registerAssetBundle(TimeloopFieldAsset::class);

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
            'timeloop/field',
            [
                'name' => $this->handle,
                'value' => $value,
                'field' => $this,
                'id' => $id,
                'namespacedId' => $namespacedId,
                'settings' => [
                    'showTime' => Timeloop::$plugin->getSettings()->showTime,
                ]
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
                'loopPeriod' => [
                    'name' => 'loopPeriod',
                    'type' => Type::string(),
                    'description' => 'The loop repeater period (daily / weekly / monthly / yearly)'
                ],
                'loopStart' => [
                    'name' => 'loopStart',
                    'type' => DateTime::getType(),
                    'description' => 'The start date of the loop'
                ],
                'loopStartHour' => [
                    'name' => 'loopStartHour',
                    'type' => DateTime::getType(),
                    'description' => 'The start hour of the loop'
                ],
                'loopEndHour' => [
                    'name' => 'loopEndHour',
                    'type' => DateTime::getType(),
                    'description' => 'The end hour of the loop'
                ],
                'loopEnd' => [
                    'name' => 'loopEnd',
                    'type' => DateTime::getType(),
                    'description' => 'The end date where the loop should stop'
                ],
                'dates' => [
                    'name' =>'dates',
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
                        $timeloopData = $source;
                        $dates = Timeloop::$plugin->timeloop->getLoop($timeloopData, $arguments['limit'] ?? 0, $arguments['futureDates'] ?? true);

                        foreach ($dates as &$date) {
                            $date = Gql::applyDirectives($source, $resolveInfo, $date);
                        }

                        return $dates;
                    }
                ]
            ]
        ]));

        TypeLoader::registerType($typeName, static function() use ($timeloopType) {
            return $timeloopType;
        });

        return $timeloopType;
    }
}


