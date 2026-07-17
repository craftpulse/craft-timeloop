<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\twigextensions;

use craft\elements\db\ElementQuery;
use craft\errors\InvalidFieldException;
use craftpulse\timeloop\models\TimeloopModel;
use craftpulse\timeloop\Timeloop;
use DateTime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use yii\web\NotFoundHttpException;

/**
 * Timeloop Twig extension.
 *
 * Exposes the `recurringDates()` Twig function.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class TimeloopTwigExtension extends AbstractExtension
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the name of the extension.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getName(): string
    {
        return 'timeloop';
    }

    /**
     * @inheritdoc
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('recurringDates', [$this, 'recurringDates']),
        ];
    }

    /**
     * Returns the recurring dates per element for the given query,
     * limited to the dates between `$start` and `$end` (inclusive).
     *
     * As of 5.1.0 the per-element date resolution is backed by the occurrence
     * index ({@see \craftpulse\timeloop\services\OccurrenceIndexService::occurrenceStarts()}),
     * which reads the requested range straight from the index when it fully
     * covers that range and otherwise falls back to live expansion. The output
     * shape and inclusive-boundary semantics are unchanged: a missing or stale
     * index never alters the returned dates.
     *
     * @param ElementQuery $query
     * @param string $field The Timeloop field handle.
     * @param string $start
     * @param string $end
     * @return array
     * @throws NotFoundHttpException if the field does not exist on the query's elements.
     * @throws \Exception if a date cannot be parsed or a recurrence cannot be expanded.
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function recurringDates(ElementQuery $query, string $field, string $start, string $end): array
    {
        $dates = [];
        $startDate = new DateTime($start);
        $endDate = new DateTime($end);

        foreach ($query->all() as $element) {
            try {
                $timeloopModel = $element->getFieldValue($field);
            } catch (InvalidFieldException) {
                throw new NotFoundHttpException("The field {$field} doesn't exist on the element query");
            }

            if (!$timeloopModel instanceof TimeloopModel || $timeloopModel->getRecurrence() === null) {
                continue;
            }

            $dates[] = [
                'entryId' => $element->id,
                'entryTitle' => $element->title ?? null,
                'dates' => Timeloop::$plugin->getOccurrenceIndex()->occurrenceStarts($element, $timeloopModel, $field, $startDate, $endDate),
            ];
        }

        return $dates;
    }
}
