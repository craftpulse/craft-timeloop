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
use craft\errors\InvalidFieldException;
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
 * `GET`-only; it changes no state.
 *
 * A missing, tampered or unresolvable token; an element that no longer exists,
 * is disabled, or is disabled for the requested site; or a field that has
 * since been removed from the element's layout all yield the same generic
 * 404, deliberately indistinguishable from one another to an anonymous
 * caller. Disabling an entry therefore stops its ICS URL from serving, and
 * re-enabling it resumes serving the same URL immediately with no new token
 * required; this is intentional, not a bug, since calendar subscriptions poll
 * and recover on their own. Any other internal failure is logged rather than
 * surfaced to the caller.
 *
 * The response carries `Cache-Control: private, no-store`: the URL's `sig`
 * query parameter is a bearer credential, and a shared cache (a CDN, a
 * corporate proxy) must never retain a copy keyed only on the URL.
 *
 * Rate limiting is deliberately not implemented here; it is delegated to the
 * edge or web server in front of Craft, the same as every other anonymous
 * Craft action.
 *
 * The download URL is generated in Twig via `craft.timeloop.icsUrl(entry, 'fieldHandle')`.
 *
 * @author CraftPulse
 * @since 5.1.0
 */
class IcsController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public $defaultAction = 'ics';

    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = ['ics'];

    // Public Methods
    // =========================================================================

    /**
     * Streams the ICS calendar for a signed element/field pair.
     *
     * @return Response The `text/calendar` download.
     * @throws BadRequestHttpException if the request is not a GET request.
     * @throws NotFoundHttpException if the token is missing, invalid, or resolves to no exportable value
     * (an unresolvable element, a disabled element, a field removed from the element's layout, or an
     * empty field value).
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
        // Read via getQueryParam(), not getRequiredQueryParam(): a missing
        // `sig` is just another unresolvable-token case and degrades to the
        // same generic 404 as a tampered one, rather than a distinguishing 400.
        $token = $this->request->getQueryParam('sig');

        if (!is_string($token) || $token === '') {
            throw new NotFoundHttpException('Calendar not found.');
        }

        $ics = Timeloop::getInstance()->getIcs();
        $decoded = $ics->validateToken($token);

        if ($decoded === null) {
            throw new NotFoundHttpException('Calendar not found.');
        }

        $element = Craft::$app->getElements()->getElementById($decoded['elementId'], null, $decoded['siteId']);

        if ($element === null || !$element->enabled || !$element->getEnabledForSite()) {
            throw new NotFoundHttpException('Calendar not found.');
        }

        try {
            // A field removed from the element's layout since the URL was
            // generated (everyday content-ops) makes the handle unresolvable;
            // that is not a server error, just another "not found" case.
            $value = $element->getFieldValue($decoded['fieldHandle']);
        } catch (InvalidFieldException) {
            throw new NotFoundHttpException('Calendar not found.');
        }

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
     * `Cache-Control: private, no-store` is set because the URL's `sig` query
     * parameter is a bearer credential (see the class docblock): a shared
     * cache must never retain a copy of the response keyed on the URL alone.
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
            ->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename))
            ->set('Cache-Control', 'private, no-store');

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
