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

/**
 * Timeloop control-panel asset bundle.
 *
 * As of 5.1.0 this is a plain, hand-written bundle: no Vite, no build step, no
 * npm. It publishes a single Garnish component ({@see timeloop.js}) and a single
 * stylesheet ({@see timeloop.css}) from `web/assets/timeloop`, and depends on
 * {@see CpAsset} so Garnish and jQuery are guaranteed loaded first.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class TimeloopAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = '@craftpulse/timeloop/web/assets/timeloop';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'timeloop.js',
        ];

        $this->css = [
            'timeloop.css',
        ];

        parent::init();
    }
}
