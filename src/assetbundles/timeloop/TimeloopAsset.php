<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\assetbundles\timeloop;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\assets\vue\VueAsset;

/**
 *
 * @author    craftpulse
 * @package   Timeloop
 * @since     1.0.0
 */
class TimeloopAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    /**
     * Initializes the bundle.
     */
    public function init(): void
    {
        // define the path that your publishable resources live
        $this->sourcePath = "@craftpulse/timeloop/web/assets/dist";

        // define the dependencies
        $this->depends = [
            CpAsset::class,
        ];

        parent::init();
    }
}
