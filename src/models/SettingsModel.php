<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\models;

use craft\base\Model;

/**
 * Timeloop Settings Model
 *
 * This is a model used to define the plugin's settings.
 *
 * Models are containers for data. Just about every time information is passed
 * between services, controllers, and templates in Craft, it’s passed via a model.
 *
 * https://craftcms.com/docs/plugins/models
 *
 * The plugin has no control-panel settings screen (`hasCpSettings = false`), so
 * [[indexHorizon]] is overridden from a `config/timeloop.php` file:
 *
 * ```php
 * return [
 *     'indexHorizon' => '+3 years',
 * ];
 * ```
 *
 * @author    craftpulse
 * @package   Timeloop
 * @since     1.0.0
 */
class SettingsModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string How far ahead the occurrence index is expanded, as a relative
     * date-modifier string (see PHP's `DateTime::modify()`), e.g. `+2 years`.
     *
     * Infinite rules cannot be fully indexed, so the index only stores
     * occurrences up to `now + indexHorizon`; the nightly `timeloop/occurrences/refresh`
     * console command rolls the window forward as time passes. A finite rule is
     * always indexed to its own natural end, never past this horizon.
     *
     * @since 5.1.0
     */
    public string $indexHorizon = '+2 years';

    /**
     * @var bool
     */
    public bool $showTime = true;
}
