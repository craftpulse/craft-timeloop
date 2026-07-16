<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;

use craft\base\SortableFieldInterface;
use craft\gql\GqlEntityRegistry;
use craft\gql\TypeLoader;
use craft\gql\types\DateTime;
use craft\helpers\DateTimeHelper;

use craft\helpers\Gql;
use craft\helpers\Json;

use craft\i18n\Locale;
use craftpulse\timeloop\assetbundles\timeloop\TimeloopAsset;
use craftpulse\timeloop\gql\types\input\TimeloopInputType;
use craftpulse\timeloop\models\TimeloopModel;
use craftpulse\timeloop\models\ValueNormalizer;

use craftpulse\timeloop\Timeloop;

use DateTimeInterface;
use DateTimeZone;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

/**
 * Timeloop field type.
 *
 * Stores a recurrence configuration and returns a [[TimeloopModel]] that
 * expands into the matching dates.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class TimeloopField extends Field implements PreviewableFieldInterface, SortableFieldInterface
{
    // Public Properties
    // =========================================================================

    /**
     * @var int Whether start and end times can be set on the loop.
     */
    public int $showTime = 0;

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('timeloop', 'Timeloop');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): string
    {
        return Craft::getAlias('@craftpulse/timeloop/icon-mask.svg');
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @since 1.2.1
     */
    public function useFieldset(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['showTime'], 'boolean'];

        return $rules;
    }

    /**
     * @inheritdoc
     *
     * Normalizes any stored or submitted value to a v2-backed [[TimeloopModel]].
     * Legacy shapes (4.x, 5.0.0 betas and 5.0.0) and legacy GraphQL mutation
     * input are upgraded in memory by {@see ValueNormalizer}, so drafts,
     * revisions, Matrix/Neo-nested values and rows the migration missed keep
     * working. Empty or unparseable values still normalize to an (empty) model.
     *
     * @throws \Exception if a submitted date value cannot be coerced (via {@see DateTimeHelper::toDateTime()}).
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof TimeloopModel) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $value = Json::decodeIfJson($value);
        }

        if (!is_array($value)) {
            $value = [];
        }

        $timezone = new DateTimeZone(Craft::$app->getTimeZone());

        return new TimeloopModel(ValueNormalizer::normalize($this->_coerceLegacyDates($value), $timezone));
    }

    /**
     * @inheritdoc
     *
     * Writes the v2 storage shape. Empty values serialize to `null`.
     *
     * @throws \Exception if the value cannot be normalized (via [[normalizeValue()]]).
     */
    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        $model = $this->normalizeValue($value, $element);

        if (!$model instanceof TimeloopModel || $model->isEmpty()) {
            return null;
        }

        return $model->toV2Array();
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
                'settings' => $this->getSettings(),
            ]
        );
    }

    /**
     * @inheritdoc
     * @throws \Exception
     */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof TimeloopModel || $value->loopStartDate === null) {
            return '';
        }

        $upcoming = Timeloop::$plugin->timeloop->getLoop($value, 1);

        if (empty($upcoming)) {
            return '<span>' . Craft::t('timeloop', 'Next up: None') . '</span>';
        }

        return '<span>' . Craft::t('timeloop', 'Next up: {date}', [
            'date' => Craft::$app->getFormatter()->asDate($upcoming[0], Locale::LENGTH_SHORT),
        ]) . '</span>';
    }

    /**
     * @param mixed                 $value           The field’s value. This will either be the [[normalizeValue() normalized value]],
     *                                               raw POST data (i.e. if there was a validation error), or null
     * @param ElementInterface|null $element         The element the field is associated with, if there is one
     *
     * @return string The input HTML.
     */
    public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        // Register our asset bundle
        Craft::$app->getView()->registerAssetBundle(TimeloopAsset::class);

        // Get our id and namespace
        $id = Craft::$app->getView()->formatInputId($this->handle);
        $nameSpacedId = Craft::$app->getView()->namespaceInputId($id);

        // Render the input template
        return Craft::$app->getView()->renderTemplate(
            'timeloop/fields/timeloop-input',
            [
                'name' => $this->handle,
                'value' => $value,
                'field' => $this,
                'required' => $this->required,
                'id' => $id,
                'nameSpacedId' => $nameSpacedId,
                'settings' => $this->getSettings(),
                'prefix' => Craft::$app->getView()->namespaceInputId(''),
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        return !$value instanceof TimeloopModel || $value->isEmpty();
    }

    /**
     * @return Type|array
     */
    public function getContentGqlType(): Type|array
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

            ],
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
                    'description' => 'The selected timestring for the monthly frequency.',
                ],
            ],
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
                    'description' => 'The loop reminder period',
                ],
                'loopStartDate' => [
                    'name' => 'loopStartDate',
                    'type' => DateTime::getType(),
                    'description' => 'The start date of the loop',
                    'resolve' => function($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $fieldName = $resolveInfo->fieldName;
                        $value = DateTimeHelper::toDateTime($source[$fieldName]);
                        $return = Gql::applyDirectives($source, $resolveInfo, $value);
                        return $return ? $return : null;
                    },
                ],
                'loopStartTime' => [
                    'name' => 'loopStartTime',
                    'type' => DateTime::getType(),
                    'description' => 'The start hour of the loop',
                    'resolve' => function($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $fieldName = $resolveInfo->fieldName;
                        $value = DateTimeHelper::toDateTime($source[$fieldName]);
                        return  $value ? $value->format('H:i') : null;
                    },
                ],
                'loopEndDate' => [
                    'name' => 'loopEndDate',
                    'type' => DateTime::getType(),
                    'description' => 'The end date of the loop',
                    'resolve' => function($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $fieldName = $resolveInfo->fieldName;
                        $value = DateTimeHelper::toDateTime($source[$fieldName]);
                        $return = Gql::applyDirectives($source, $resolveInfo, $value);
                        return $return ? $return : null;
                    },
                ],
                'loopEndTime' => [
                    'name' => 'loopEndTime',
                    'type' => DateTime::getType(),
                    'description' => 'The end hour of the loop',
                    'resolve' => function($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $fieldName = $resolveInfo->fieldName;
                        $value = DateTimeHelper::toDateTime($source[$fieldName]);
                        return  $value ? $value->format('H:i') : null;
                    },
                ],
                'getReminder' => [
                    'name' => 'getReminder',
                    'type' => DateTime::getType(),
                    'resolve' => function($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $reminder = Timeloop::$plugin->timeloop->getReminder($source);

                        return false == $reminder ? null : Gql::applyDirectives($source, $resolveInfo, $reminder);
                    },
                ],
                'getDates' => [
                    'name' => 'getDates',
                    'type' => Type::listOf(DateTime::getType()),
                    'args' => [
                        'limit' => [
                            'type' => Type::int(),
                            'name' => "limit",
                            'description' => "Limit how many dates you want in return. By default it returns 100 dates",
                        ],
                        'futureDates' => [
                            'type' => Type::boolean(),
                            'name' => "futureDates",
                            'description' => "Set to false if you want to dates from the start date. By default it returns only future dates",
                        ],
                    ],
                    'resolve' => function($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $dates = Timeloop::$plugin->timeloop->getLoop($source, $arguments['limit'] ?? 0, $arguments['futureDates'] ?? true);

                        if ($dates) {
                            foreach ($dates as &$date) {
                                $date = Gql::applyDirectives($source, $resolveInfo, DateTimeHelper::toDateTime($date));
                            }

                            return $dates;
                        }

                        return null;
                    },
                ],
                'getUpcoming' => [
                    'name' => 'getUpcoming',
                    'type' => DateTime::getType(),
                    'resolve' => function($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        $upcoming = Timeloop::$plugin->timeloop->getLoop($source, 1);

                        if ($upcoming && $upcoming !== []) {
                            return  Gql::applyDirectives($source, $resolveInfo, DateTimeHelper::toDateTime($upcoming[0]));
                        }

                        return null;
                    },
                ],
            ],
        ]));

        TypeLoader::registerType($typeName, static function() use ($timeloopType) {
            return $timeloopType;
        });

        return $timeloopType;
    }

    /**
     * @return Type|array
     */
    public function getContentGqlMutationArgumentType(): Type|array
    {
        return TimeloopInputType::getType($this);
    }

    // Private Methods
    // =========================================================================

    /**
     * Coerces control-panel/GraphQL legacy date inputs into ISO-8601 strings.
     *
     * The pure {@see ValueNormalizer} accepts date-time objects and strings but
     * not Craft's date-field POST arrays (`{date, timezone}`); coercing them
     * here (with a running Craft app available) keeps the normalizer pure. Only
     * legacy-shaped values are touched; v2 values pass through untouched.
     *
     * @param array $value
     * @return array
     * @throws \Exception if a date value cannot be coerced (via {@see DateTimeHelper::toDateTime()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _coerceLegacyDates(array $value): array
    {
        if (isset($value['version'])) {
            return $value;
        }

        foreach (['loopStartDate', 'loopEndDate', 'loopStartTime', 'loopEndTime'] as $key) {
            if (!isset($value[$key]) || $value[$key] instanceof DateTimeInterface || is_string($value[$key])) {
                continue;
            }

            $value[$key] = DateTimeHelper::toDateTime($value[$key]) ?: null;
        }

        return $value;
    }
}
