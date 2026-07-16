<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\controllers;

use Craft;
use craft\web\Controller;
use craftpulse\timeloop\fields\TimeloopField;
use craftpulse\timeloop\models\TimeloopModel;
use craftpulse\timeloop\models\ValueNormalizer;
use DateTimeZone;
use Throwable;
use yii\web\Response;

/**
 * Live recurrence-summary endpoint.
 *
 * A single, computed, control-panel-only action backing the field input's live
 * summary line. It takes the curated rule subset the field editor posts, builds
 * a {@see TimeloopModel} through the exact same normalization the save path uses
 * ({@see TimeloopField::coerceInputDates()} + {@see ValueNormalizer::inputToV2()}),
 * and returns the library's localized human-readable summary. It changes no
 * state and touches no stored data; it exists only so the editor can preview the
 * rule as it is built.
 *
 * @author CraftPulse
 * @since 5.1.0
 */
class SummaryController extends Controller
{
    // Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * @inheritdoc
     */
    public $defaultAction = 'index';

    // Public Methods
    // =========================================================================

    /**
     * Returns a localized human-readable summary of the posted rule subset.
     *
     * @return Response A JSON response of the form `{summary: string|null}`.
     * @throws \yii\web\BadRequestHttpException if the request is not a POST request.
     * @throws \yii\web\ForbiddenHttpException if the user lacks control-panel access.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function actionIndex(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requirePermission('accessCp');

        $request = Craft::$app->getRequest();
        $input = $request->getBodyParam('input', []);
        $locale = (string)$request->getBodyParam('locale', Craft::$app->language);

        if (!is_array($input)) {
            return $this->asJson(['summary' => null]);
        }

        return $this->asJson(['summary' => $this->_summary($input, $locale)]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the localized summary from the posted input subset.
     *
     * Any failure (a malformed advanced rule, an unparseable date) degrades to a
     * null summary with a logged warning: the endpoint is a non-critical preview,
     * so it must never surface an exception message to the client.
     *
     * @param array $input The raw input POST subset.
     * @param string $locale The locale to render the summary in.
     * @return ?string
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _summary(array $input, string $locale): ?string
    {
        try {
            $timezone = new DateTimeZone(Craft::$app->getTimeZone());
            $v2 = ValueNormalizer::inputToV2(TimeloopField::coerceInputDates($input), $timezone);

            return (new TimeloopModel($v2))->getRecurrence()?->summary($locale);
        } catch (Throwable $e) {
            Craft::warning('Timeloop: could not build a rule summary: ' . $e->getMessage(), __METHOD__);

            return null;
        }
    }
}
