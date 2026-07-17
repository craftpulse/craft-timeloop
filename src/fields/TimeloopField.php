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
use craft\gql\types\DateTime;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;

use craft\helpers\Gql;
use craft\helpers\Json;
use craft\helpers\UrlHelper;

use craft\i18n\Locale;
use craftpulse\timeloop\assetbundles\timeloop\TimeloopAsset;
use craftpulse\timeloop\gql\types\input\TimeloopInputType;
use craftpulse\timeloop\migrations\Install;
use craftpulse\timeloop\models\TimeloopModel;
use craftpulse\timeloop\models\ValueNormalizer;

use craftpulse\timeloop\Timeloop;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Throwable;
use yii\base\NotSupportedException;

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

    /**
     * @var bool Whether public-holiday exclusion is enabled for every value of this field.
     *
     * The field-level holiday support. When on, each value expands with the
     * country's public holidays subtracted (resolved fresh at read time, never
     * persisted); a value can still override this through the GraphQL `holidays`
     * input. It is stamped onto each value's
     * {@see TimeloopModel::$holidayEnabledDefault} in [[normalizeValue()]] and is
     * never written into a value's stored JSON.
     */
    public bool $enableHolidays = false;

    /**
     * @var ?string The holiday country applied to every value of this field.
     *
     * Middle link in the read-time country-resolution chain (a value's explicit
     * GraphQL country, then this field country, then the site-locale-derived
     * country, then disabled; see {@see \craftpulse\timeloop\services\HolidaysService::resolveCountry()}).
     * Stamped onto each value's {@see TimeloopModel::$holidayCountryDefault} in
     * [[normalizeValue()]] and never written into a value's stored JSON. Left
     * blank, the country is derived from the site locale.
     */
    public ?string $holidaysCountry = null;

    /**
     * @var ?string The holiday region applied to every value of this field, e.g. `DE-BY`.
     *
     * Stamped onto each value's {@see TimeloopModel::$holidayRegionDefault} in
     * [[normalizeValue()]] and never written into a value's stored JSON.
     */
    public ?string $holidaysRegion = null;

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

    /**
     * Coerces the control-panel input POST's date-picker arrays into plain strings.
     *
     * The date/time form macros post `{date|time, timezone, locale}` arrays; the
     * pure {@see ValueNormalizer::inputToV2()} expects plain `Y-m-d`/`H:i`
     * strings. This shared coercion (used by both [[normalizeValue()]] and
     * {@see \craftpulse\timeloop\controllers\SummaryController}) needs a running
     * Craft app for {@see DateTimeHelper::toDateTime()}, so it lives here rather
     * than in the pure normalizer. Values already given as strings pass through.
     *
     * @param array $input The raw input POST subset.
     * @return array The same subset with `startDate`/`until` reduced to `Y-m-d` and
     * `startTime`/`endTime` reduced to `H:i` strings (or null).
     * @throws \Exception if a date value cannot be coerced (via {@see DateTimeHelper::toDateTime()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public static function coerceInputDates(array $input): array
    {
        foreach (['startDate', 'until'] as $key) {
            $input[$key] = self::_coerceDate($input[$key] ?? null, 'Y-m-d');
        }

        foreach (['startTime', 'endTime'] as $key) {
            $input[$key] = self::_coerceDate($input[$key] ?? null, 'H:i');
        }

        return $input;
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
     *
     * Overrides the default {@see Field::getSortOption()}, which would order
     * by the field's raw stored JSON column (`elements_sites.content`,
     * extracted with no notion of dates), an opaque and effectively
     * meaningless sequence for a recurrence value. Instead this orders by the
     * element's next upcoming occurrence, read from `{{%timeloop_occurrences}}`:
     * a correlated `MIN(occurrenceStart)` subquery scoped to this field's
     * handle and to `occurrenceStart >= now`, referencing the `elements` /
     * `elements_sites` aliases the element-index query already joins at the
     * point sort options are applied (see {@see \craft\elements\db\ElementQuery::prepare()},
     * which joins both onto the outer query in addition to the subquery, and
     * {@see \craft\base\Element::_indexOrderByColumns()}, which appends the
     * requested direction as a literal suffix onto this string for a
     * field-sourced sort option, rather than invoking it as a callable the way
     * a native `defineSortOptions()` entry can be).
     *
     * Elements with no upcoming occurrence (unindexed, or every occurrence
     * already past) sort last regardless of direction: the returned `orderBy`
     * is `"($sub) IS NULL, ($sub)"`, and because the direction suffix only
     * binds to the final comma-separated term, the `IS NULL` term is always
     * evaluated ascending (0 = has an upcoming occurrence, 1 = none), pushing
     * NULLs to the bottom in both ASC and DESC requests.
     *
     * @throws NotSupportedException if the field isn't attached to a layout element (see parent).
     */
    public function getSortOption(): array
    {
        if (!isset($this->layoutElement)) {
            throw new NotSupportedException('getSortOption() not supported by ' . $this->name);
        }

        $db = Craft::$app->getDb();
        $now = $db->quoteValue(Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime()));
        $handle = $db->quoteValue($this->handle);

        $next = sprintf(
            '(SELECT MIN([[tl_sort.occurrenceStart]]) FROM %s [[tl_sort]] WHERE [[tl_sort.elementId]] = [[elements.id]] AND [[tl_sort.siteId]] = [[elements_sites.siteId]] AND [[tl_sort.fieldHandle]] = %s AND [[tl_sort.occurrenceStart]] >= %s)',
            Install::OCCURRENCES_TABLE,
            $handle,
            $now,
        );

        return [
            'label' => Craft::t('site', $this->name),
            'orderBy' => "$next IS NULL, $next",
            'attribute' => isset($this->layoutElement->handle)
                ? "fieldInstance:{$this->layoutElement->uid}"
                : "field:$this->uid",
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['showTime'], 'boolean'];
        $rules[] = [['enableHolidays'], 'boolean'];
        $rules[] = [['holidaysCountry'], 'filter', 'filter' => 'strtolower', 'skipOnEmpty' => true];
        $rules[] = [['holidaysCountry'], 'match', 'pattern' => '/^[a-z]{2}$/', 'skipOnEmpty' => true, 'message' => Craft::t('timeloop', 'Enter a two-letter country code, for example "be".')];
        $rules[] = [['holidaysCountry'], 'default', 'value' => null];
        $rules[] = [['holidaysRegion'], 'filter', 'filter' => fn($value) => strtoupper(trim((string)$value)), 'skipOnEmpty' => true];
        $rules[] = [['holidaysRegion'], 'default', 'value' => null];

        return $rules;
    }

    /**
     * @inheritdoc
     *
     * Normalizes any stored or submitted value to a v2-backed [[TimeloopModel]].
     * Three input shapes are recognized:
     *
     * - a control-panel form submission (discriminated by the hidden `mode`
     *   key the new UI always posts) is built into v2 by
     *   {@see ValueNormalizer::inputToV2()} after its date-picker POST arrays
     *   are coerced to plain strings by [[coerceInputDates()]];
     * - a legacy shape (4.x, 5.0.0 betas and 5.0.0) or legacy GraphQL mutation
     *   input is upgraded by {@see ValueNormalizer::normalize()};
     * - a stored v2 value is normalized idempotently by the same method.
     *
     * So drafts, revisions, Matrix/Neo-nested values and rows the migration
     * missed keep working. Empty or unparseable values still normalize to an
     * (empty) model.
     *
     * @throws \Exception if a submitted date value cannot be coerced (via {@see DateTimeHelper::toDateTime()}),
     * or if a stored date string cannot be parsed (via {@see ValueNormalizer::normalize()} /
     * {@see ValueNormalizer::inputToV2()}).
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

        // A control-panel submission (hidden `mode` key) and a v2-native
        // GraphQL mutation (a raw `rrule`) both build v2 through the shared
        // {@see ValueNormalizer::inputToV2()}; a legacy shape (stored, draft,
        // Matrix/Neo-nested, or a legacy GraphQL mutation) is upgraded by
        // {@see ValueNormalizer::normalize()}.
        if (isset($value['mode'])) {
            $timezone = new DateTimeZone(Craft::$app->getTimeZone());
            $v2 = ValueNormalizer::inputToV2(self::coerceInputDates($value), $timezone);
        } elseif ($this->_isV2NativeInput($value)) {
            $timezone = $this->_resolveTimezone(self::_string($value['timezone'] ?? null));
            $v2 = ValueNormalizer::inputToV2(self::coerceInputDates(self::_gqlV2Input($value)), $timezone);
        } else {
            $timezone = new DateTimeZone(Craft::$app->getTimeZone());
            $v2 = ValueNormalizer::normalize($this->_coerceLegacyDates($value), $timezone);
        }

        $model = new TimeloopModel($v2);

        // Stamp the field-level holiday settings onto the value for the read-time
        // holiday resolution chain. Runtime only: they are excluded from storage.
        $model->holidayEnabledDefault = $this->enableHolidays;
        $model->holidayCountryDefault = $this->holidaysCountry;
        $model->holidayRegionDefault = $this->holidaysRegion;

        return $model;
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
     * Renders the field settings template.
     *
     * The template receives the field itself (as `field`) so it can surface
     * per-attribute validation errors, e.g. `field.getErrors('holidaysCountry')`.
     *
     * @return string|null
     * @throws \Throwable if the settings template cannot be rendered.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function getSettingsHtml(): ?string
    {
        // Render the settings template
        return Craft::$app->getView()->renderTemplate(
            'timeloop/fields/timeloop-settings',
            [
                'settings' => $this->getSettings(),
                'field' => $this,
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
     * Renders the field input: the form-macro editor plus the per-instance
     * Garnish component bootstrap.
     *
     * @param mixed                 $value           The field’s value. This will either be the [[normalizeValue() normalized value]],
     *                                               raw POST data (i.e. if there was a validation error), or null
     * @param ElementInterface|null $element         The element the field is associated with, if there is one
     *
     * @return string The input HTML.
     * @throws \Exception if the stored value cannot be normalized (see [[normalizeValue()]]).
     * @throws \Throwable if the input template cannot be rendered.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(TimeloopAsset::class);

        if (!$value instanceof TimeloopModel) {
            $value = $this->normalizeValue($value, $element);
        }

        $id = $view->formatInputId($this->handle);

        // A representable rule pre-fills the simple controls; an advanced rule
        // (BYSETPOS, BYMONTH, ...) is round-tripped read-only until the editor
        // opts into the simple editor and drops the advanced parts.
        $representable = ValueNormalizer::isUiRepresentable($value->rrule);

        return $view->renderTemplate('timeloop/fields/timeloop-input', [
            'name' => $this->handle,
            'value' => $value,
            'field' => $this,
            'required' => $this->required,
            'id' => $id,
            'showTime' => (bool)$this->showTime,
            'representable' => $representable,
            'rule' => ValueNormalizer::rruleToInput($value->rrule),
            'summaryAction' => UrlHelper::actionUrl('timeloop/summary'),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        return !$value instanceof TimeloopModel || $value->isEmpty();
    }

    /**
     * @inheritdoc
     *
     * Flags the element when the value's `rrule` cannot actually be parsed by
     * the recurrence engine. `normalizeValue()` accepts any non-empty `rrule`
     * string as-is (a raw GraphQL mutation, see [[_gqlV2Input()]], can post one
     * verbatim); without this check a syntactically invalid rule saves
     * silently and only fails the first time something expands the recurrence
     * (a Twig `entry.field.summary`, the `occurrences`/`summary` GraphQL
     * resolvers, the element index preview, ...), as an uncaught exception
     * rather than a validation error.
     *
     * [[RecurrenceModel::isInfinite()]] is the cheapest call that forces the
     * engine to actually build the recurrence set (see
     * {@see \craftpulse\timeloop\models\RecurrenceModel}), which is where the
     * underlying RRule library parses the rule (and any `UNTIL`/`COUNT`); a
     * value that constructs fine (see {@see TimeloopModel::init()}, which
     * degrades a malformed `UNTIL` to a null `loopEndDate` instead of
     * throwing) but carries an otherwise-invalid rule fails here instead.
     *
     * @return array
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function getElementValidationRules(): array
    {
        return [
            [
                function(ElementInterface $element) {
                    $value = $element->getFieldValue($this->handle);

                    if (!$value instanceof TimeloopModel || $value->rrule === null || $value->rrule === '') {
                        return;
                    }

                    try {
                        $value->getRecurrence()?->isInfinite();
                    } catch (Throwable $e) {
                        $element->addError("field:$this->handle", Craft::t('timeloop', 'The recurrence rule is invalid: {message}', [
                            'message' => $e->getMessage(),
                        ]));
                    }
                },
            ],
        ];
    }

    /**
     * @inheritdoc
     *
     * Keeps the occurrence index in sync with the saved value. The field's own
     * `afterElementSave` is used (rather than a global `Elements` save event)
     * because Craft calls it with the exact element that carries the field, its
     * site and this field instance, so the index rows land under the right
     * `elementId`/`siteId`/`fieldHandle` without walking field layouts, it is
     * automatically scoped to elements that actually have the field, and for a
     * Matrix/Neo-nested value the element passed is the nested entry (the owner
     * of the field), which is exactly the `elementId` the index keys on. Drafts,
     * revisions and over-threshold series are handled inside
     * {@see \craftpulse\timeloop\services\OccurrenceIndexService::handleElementSave()}.
     *
     * @throws \Throwable if the reindex fails (see {@see \craftpulse\timeloop\services\OccurrenceIndexService::handleElementSave()}).
     */
    public function afterElementSave(ElementInterface $element, bool $isNew): void
    {
        parent::afterElementSave($element, $isNew);

        Timeloop::getInstance()?->getOccurrenceIndex()->handleElementSave($element, $this);
    }

    /**
     * @return Type|array
     */
    public function getContentGqlType(): Type|array
    {
        $typeName = $this->handle;

        // #82: dedupe the shared inner types through GqlEntityRegistry::getOrCreate,
        // which registers each name with the TypeLoader exactly once (see
        // {@see GqlEntityRegistry::createEntity()}). Two Timeloop fields on one
        // entry type reuse the same `timestring`/`loopPeriod` types instead of
        // colliding on a second creation.
        //
        // Known, deliberately-accepted collision: these two inner type names
        // (`timestring`, `loopPeriod`) are global in the GraphQL schema, not
        // namespaced per field. If a user names a *different* custom field
        // (of any type, on any element) literally `timestring` or `loopPeriod`,
        // that field's own top-level GQL type would collide with these names.
        // A reviewer proposed prefixing them (e.g. `timeloop_timestring`) to
        // close this off; that was rejected because it renames types visible
        // in schema introspection, which is a breaking change for any existing
        // consumer and violates the additive-only guarantee of this GraphQL
        // surface. The collision is pathological (it requires a user to
        // deliberately pick one of these two exact handles) and is accepted
        // as a known limitation rather than fixed by a breaking rename.
        $timestringType = GqlEntityRegistry::getOrCreate('timestring', fn() => new ObjectType([
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

        $periodType = GqlEntityRegistry::getOrCreate('loopPeriod', fn() => new ObjectType([
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

        $timeloopType = GqlEntityRegistry::getOrCreate($typeName, fn() => new ObjectType([
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
                    // #83: the pre-5.1 definition carried no resolver, so the
                    // GraphQL default resolver looked up a `loopReminder`
                    // property that never existed on the value model (the real
                    // properties are `loopReminderPeriod`/`loopReminderValue`)
                    // and every query returned null. The schema shape is kept
                    // identical (still a nullable `String`); the resolver now
                    // returns the reminder period unit (e.g. `days`), or null
                    // when the value carries no reminder.
                    'resolve' => static function($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        return $source instanceof TimeloopModel ? $source->loopReminderPeriod : null;
                    },
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
                // 5.1.0 additive surface (v2 engine). Every resolver below
                // routes through the normalized [[TimeloopModel]] and the
                // holiday-aware screens API, never a raw RecurrenceModel.
                'rrule' => [
                    'name' => 'rrule',
                    'type' => Type::string(),
                    'description' => 'The raw RFC 5545 RRULE string (without DTSTART), or null when the value carries no rule.',
                    'resolve' => static function($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        return $source instanceof TimeloopModel ? $source->rrule : null;
                    },
                ],
                'timezone' => [
                    'name' => 'timezone',
                    'type' => Type::string(),
                    'description' => 'The IANA timezone the recurrence is stored and expanded in.',
                    'resolve' => static function($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        return $source instanceof TimeloopModel ? $source->timezone : null;
                    },
                ],
                'summary' => [
                    'name' => 'summary',
                    'type' => Type::string(),
                    'description' => 'A localized, human-readable description of the recurrence rule (holiday exclusions are not part of the wording).',
                    'args' => [
                        'locale' => [
                            'name' => 'locale',
                            'type' => Type::string(),
                            'description' => 'The locale to render in (e.g. "nl", "fr"). Defaults to the current site language.',
                        ],
                    ],
                    'resolve' => static function($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        return $source instanceof TimeloopModel ? $source->getSummary($arguments['locale'] ?? null) : null;
                    },
                ],
                'isActiveNow' => [
                    'name' => 'isActiveNow',
                    'type' => Type::boolean(),
                    'description' => 'Whether an occurrence is in progress right now (holiday-aware).',
                    'resolve' => static function($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        return $source instanceof TimeloopModel ? $source->getIsActiveNow() : null;
                    },
                ],
                'nextOccurrence' => [
                    'name' => 'nextOccurrence',
                    'type' => DateTime::getType(),
                    'description' => 'The first occurrence after now (holiday-aware), or null.',
                    'resolve' => static function($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        if (!$source instanceof TimeloopModel) {
                            return null;
                        }

                        $next = $source->getNextOccurrence();

                        return $next !== null ? Gql::applyDirectives($source, $resolveInfo, DateTimeHelper::toDateTime($next)) : null;
                    },
                ],
                'occurrences' => [
                    'name' => 'occurrences',
                    'type' => Type::listOf(DateTime::getType()),
                    'description' => 'The occurrences of the value, bounded and holiday-aware.',
                    'args' => [
                        'rangeStart' => [
                            'name' => 'rangeStart',
                            'type' => DateTime::getType(),
                            'description' => 'The lower boundary (inclusive). Defaults to the series start.',
                        ],
                        'rangeEnd' => [
                            'name' => 'rangeEnd',
                            'type' => DateTime::getType(),
                            'description' => 'The upper boundary (inclusive). Defaults to the default horizon.',
                        ],
                        'limit' => [
                            'name' => 'limit',
                            'type' => Type::int(),
                            'description' => 'The maximum number of occurrences to return.',
                        ],
                    ],
                    'resolve' => static function($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        if (!$source instanceof TimeloopModel) {
                            return null;
                        }

                        $from = isset($arguments['rangeStart']) ? DateTimeHelper::toDateTime($arguments['rangeStart']) : null;
                        $to = isset($arguments['rangeEnd']) ? DateTimeHelper::toDateTime($arguments['rangeEnd']) : null;
                        $occurrences = $source->occurrences(
                            $from ?: null,
                            $to ?: null,
                            $arguments['limit'] ?? null,
                        );

                        return array_map(
                            static fn($date) => Gql::applyDirectives($source, $resolveInfo, DateTimeHelper::toDateTime($date)),
                            $occurrences,
                        );
                    },
                ],
                'exceptions' => [
                    'name' => 'exceptions',
                    'type' => Type::listOf(DateTime::getType()),
                    'description' => 'The value\'s stored exclusion dates (exdates). Holiday exclusions are resolved at read time and are not included here.',
                    // #86: this used to construct each stored exdate at
                    // midnight in the value's timezone, disagreeing with the
                    // ICS export and every recurrence-engine read, which
                    // anchor exclusions to the series' `dtstart` time of day
                    // (see {@see RecurrenceModel::_atOccurrenceTime()}). It now
                    // reuses that same anchoring via
                    // {@see RecurrenceModel::storedExclusionDates()}, which is
                    // deliberately *not* {@see RecurrenceModel::exclusionDates()}:
                    // this field is documented as the stored exdates only, and
                    // must not leak the resolved-holiday exclusions
                    // {@see RecurrenceModel::exclusionDates()} also carries.
                    'resolve' => static function($source, array $arguments, $context, ResolveInfo $resolveInfo) {
                        if (!$source instanceof TimeloopModel) {
                            return null;
                        }

                        $recurrence = $source->getRecurrence();

                        if ($recurrence === null) {
                            return [];
                        }

                        return array_map(
                            static fn(DateTimeImmutable $date) => Gql::applyDirectives($source, $resolveInfo, $date),
                            $recurrence->storedExclusionDates(),
                        );
                    },
                ],
            ],
        ]));

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
     * Coerces a single date-picker POST value into a formatted string, or null.
     *
     * @param mixed $value A date/time picker POST array, a string, or null/empty.
     * @param string $format The target format (`Y-m-d` or `H:i`).
     * @return ?string
     * @throws \Exception if the value cannot be coerced (via {@see DateTimeHelper::toDateTime()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _coerceDate(mixed $value, string $format): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return trim($value) !== '' ? trim($value) : null;
        }

        $date = DateTimeHelper::toDateTime($value);

        return $date instanceof DateTimeInterface ? $date->format($format) : null;
    }

    /**
     * Normalizes a scalar input value into a non-empty trimmed string, or null.
     *
     * @param mixed $value
     * @return ?string
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _string(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * Returns whether a value is a v2-native GraphQL mutation input.
     *
     * A stored v2 value carries a `version` (and a `dtstart`) and must go
     * through {@see ValueNormalizer::normalize()} so it round-trips unchanged;
     * a legacy mutation uses the `loopPeriod` shape. Only a mutation that posts
     * a raw `rrule` without those storage keys is a v2-native input the
     * {@see _gqlV2Input()} mapper should handle. Without this guard a stored v2
     * value (which also has an `rrule`) would be mangled into an empty value.
     *
     * @param array $value The raw decoded value.
     * @return bool
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _isV2NativeInput(array $value): bool
    {
        if (array_key_exists('version', $value) || array_key_exists('dtstart', $value)) {
            return false;
        }

        return self::_string($value['rrule'] ?? null) !== null;
    }

    /**
     * Maps a v2-native GraphQL mutation input onto the {@see ValueNormalizer::inputToV2()} shape.
     *
     * Reached only when the mutation posts a raw `rrule`. The value is built in
     * advanced mode so the provided rule round-trips verbatim, while the start
     * date/time come from `loopStartDate`/`loopStartTime`, the window end from
     * `loopEndTime`, and the v2 exclusion/extra/holiday keys are carried across.
     * The date-picker coercion in [[coerceInputDates()]] still runs afterwards,
     * so GraphQL `DateTime` scalars and plain strings both work.
     *
     * @param array $value The raw GraphQL mutation input.
     * @return array The input subset {@see ValueNormalizer::inputToV2()} expects.
     * @throws \Exception if an exclusion/extra date cannot be coerced (via [[_coerceDateList()]]).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _gqlV2Input(array $value): array
    {
        $holidays = is_array($value['holidays'] ?? null) ? $value['holidays'] : [];

        return [
            'mode' => 'advanced',
            'rrule' => self::_string($value['rrule'] ?? null),
            'startDate' => $value['loopStartDate'] ?? null,
            'startTime' => $value['loopStartTime'] ?? $value['loopStartDate'] ?? null,
            'endTime' => $value['loopEndTime'] ?? null,
            'exdates' => self::_coerceDateList($value['exdates'] ?? []),
            'rdates' => self::_coerceDateList($value['rdates'] ?? []),
            'holidaysEnabled' => (bool)($holidays['enabled'] ?? false),
            'holidaysCountry' => $holidays['country'] ?? null,
            'holidaysRegion' => $holidays['region'] ?? null,
        ];
    }

    /**
     * Coerces a list of GraphQL date inputs into plain `Y-m-d` strings.
     *
     * @param mixed $value A list of date strings or `DateTime` scalars, or a scalar.
     * @return string[]
     * @throws \Exception if a value cannot be coerced (via {@see DateTimeHelper::toDateTime()}).
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private static function _coerceDateList(mixed $value): array
    {
        $dates = [];

        foreach ((array)$value as $date) {
            $coerced = self::_coerceDate($date, 'Y-m-d');

            if ($coerced !== null) {
                $dates[] = $coerced;
            }
        }

        return $dates;
    }

    /**
     * Resolves a user-supplied IANA timezone, falling back to the system timezone.
     *
     * @param ?string $timezone The requested IANA identifier, or null.
     * @return DateTimeZone
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _resolveTimezone(?string $timezone): DateTimeZone
    {
        if ($timezone !== null && in_array($timezone, timezone_identifiers_list(), true)) {
            return new DateTimeZone($timezone);
        }

        return new DateTimeZone(Craft::$app->getTimeZone());
    }

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
