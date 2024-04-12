<?php

namespace percipiolondon\timeloop\twigextensions;

use DateTime;
use Craft;
use craft\elements\db\ElementQuery;
use percipiolondon\timeloop\models\TimeloopModel;
use percipiolondon\timeloop\Timeloop;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use yii\web\NotFoundHttpException;

class TimeloopTwigExtension extends AbstractExtension
{
    public function getName(): string
    {
        return 'timeloop';
    }

    public function getFunctions()
    {
        return [
            new TwigFunction('recurringDates', [$this, 'recurringDates']),
        ];
    }

    public function recurringDates(ElementQuery $query, string $field, string $start, string $end): ?array
    {
        $dates = [];

        if ($query) {
            foreach ($query->all() as $entry) {
                try {
                    $timeloopModel = $entry->getFieldValue($field);
                    $startDate = new DateTime($start);
                    $endDate = new DateTime($end);

                    $loops = Timeloop::$plugin->timeloop->getLoop($timeloopModel, 0, false);

                    if (!is_null($loops)) {
                        $loops = Timeloop::$plugin->timeloop->getLoopBetweenDates($loops, $startDate, $endDate);
                        $dates[] = [
                            'entryId' => $entry->id,
                            'entryTitle' => $entry->title,
                            'dates' => $loops
                        ];
                    }

                } catch (NotFoundHttpException $exception) {
                    // field doesn't exist
                    throw new NotFoundHttpException('The field does\'nt exist on the element query');
                }
            }
        }

        return $dates;
    }
}
