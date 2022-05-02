<?php
/**
 * Timeloop plugin for Craft CMS 3.x
 *
 * This is a plugin to make repeating dates
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2021 percipioglobal
 */

namespace percipiolondon\timeloop\models;

use craft\base\Model;
use percipiolondon\timeloop\Timeloop;

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
 * @author    percipioglobal
 * @package   Timeloop
 * @since     1.0.0
 */
class SettingsModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool
     */
    public bool $showTime = true;
}
