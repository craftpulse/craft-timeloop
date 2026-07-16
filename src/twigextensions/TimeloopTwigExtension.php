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
     * @param ElementQuery $query
     * @param string $field The Timeloop field handle.
     * @param string $start
     * @param string $end
     * @return array
     * @throws NotFoundHttpException
     * @throws \Exception
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

            if (!$timeloopModel instanceof TimeloopModel) {
                continue;
            }

            $loops = Timeloop::$plugin->timeloop->getLoop($timeloopModel, 0, false);

            if ($loops === null) {
                continue;
            }

            $dates[] = [
                'entryId' => $element->id,
                'entryTitle' => $element->title ?? null,
                'dates' => Timeloop::$plugin->timeloop->getLoopBetweenDates($loops, $startDate, $endDate),
            ];
        }

        return $dates;
    }
}
