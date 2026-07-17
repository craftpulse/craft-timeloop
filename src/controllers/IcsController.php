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
use craft\base\ElementInterface;
use craft\helpers\FileHelper;
use craft\web\Controller;
use craftpulse\timeloop\models\TimeloopModel;
use craftpulse\timeloop\Timeloop;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Public, token-guarded ICS export endpoint.
 *
 * The plugin's first anonymous endpoint. It is not protected by a control-panel
 * session or a permission: access is granted by a signed token (an HMAC over
 * `elementId|siteId|fieldHandle` keyed with the app security key, see
 * {@see \craftpulse\timeloop\services\IcsService::signToken()}), so the URL
 * carries no enumerable, forgeable identifiers. The action is read-only and
 * `GET`-only; it changes no state. A missing, tampered or unresolvable token
 * yields a generic 404, and any internal failure is logged rather than
 * surfaced to the anonymous caller.
 *
 * The download URL is generated in Twig via `craft.timeloop.icsUrl(entry, 'fieldHandle')`.
 *
 * @author CraftPulse
 * @since 5.1.0
 */
class IcsController extends Controller
{
    // Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = ['ics'];

    /**
     * @inheritdoc
     */
    public $defaultAction = 'ics';

    // Public Methods
    // =========================================================================

    /**
     * Streams the ICS calendar for a signed element/field pair.
     *
     * @return Response The `text/calendar` download.
     * @throws BadRequestHttpException if the request is not a GET request.
     * @throws NotFoundHttpException if the token is missing, invalid, or resolves to no exportable value.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function actionIcs(): Response
    {
        if (!$this->request->getIsGet()) {
            throw new BadRequestHttpException('This endpoint only accepts GET requests.');
        }

        // The query param is `sig`, not `token`: Craft reserves `token` for its
        // own route/preview tokens (see GeneralConfig::$tokenParam) and would
        // reject the request with "Invalid token" before this action runs.
        $token = (string)$this->request->getRequiredQueryParam('sig');
        $ics = Timeloop::getInstance()->getIcs();
        $decoded = $ics->validateToken($token);

        if ($decoded === null) {
            throw new NotFoundHttpException('Calendar not found.');
        }

        $element = Craft::$app->getElements()->getElementById($decoded['elementId'], null, $decoded['siteId']);

        if ($element === null) {
            throw new NotFoundHttpException('Calendar not found.');
        }

        $value = $element->getFieldValue($decoded['fieldHandle']);

        if (!$value instanceof TimeloopModel || $value->isEmpty()) {
            throw new NotFoundHttpException('Calendar not found.');
        }

        try {
            $body = $ics->calendarFor(
                $value,
                (string)($element->title ?? $element->getUiLabel()),
                sprintf('%s-%s@timeloop', $element->uid, $decoded['fieldHandle']),
            );
        } catch (Throwable $e) {
            Craft::error('Timeloop: could not generate an ICS calendar: ' . $e->getMessage(), __METHOD__);

            throw new NotFoundHttpException('Calendar not found.');
        }

        return $this->_download($body, $this->_filename($element, $decoded['fieldHandle']));
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the ICS body as a `text/calendar` attachment response.
     *
     * @param string $body The serialized `VCALENDAR`.
     * @param string $filename The download filename.
     * @return Response
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _download(string $body, string $filename): Response
    {
        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->content = $body;
        $response->getHeaders()
            ->set('Content-Type', 'text/calendar; charset=utf-8')
            ->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    /**
     * Builds a safe ICS filename for an element/field pair.
     *
     * @param ElementInterface $element
     * @param string $fieldHandle
     * @return string
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _filename(ElementInterface $element, string $fieldHandle): string
    {
        $base = FileHelper::sanitizeFilename(
            (string)($element->title ?? $fieldHandle),
            ['asciiOnly' => true, 'separator' => '-'],
        );

        return sprintf('%s.ics', $base !== '' ? strtolower($base) : 'timeloop');
    }
}
