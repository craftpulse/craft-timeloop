<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use craft\web\twig\variables\CraftVariable;
use craftpulse\timeloop\assetbundles\timeloop\TimeloopAsset;
use craftpulse\timeloop\fields\TimeloopField;
use craftpulse\timeloop\models\SettingsModel as Settings;
use craftpulse\timeloop\services\HolidaysService;
use craftpulse\timeloop\services\TimeloopService;
use craftpulse\timeloop\twigextensions\TimeloopTwigExtension;
use craftpulse\timeloop\variables\TimeloopVariable;
use nystudio107\pluginvite\services\VitePluginService;
use yii\base\Event;

/**
 * Timeloop plugin entry point.
 *
 * @property VitePluginService $vite
 * @property TimeloopService $timeloop
 * @property HolidaysService $holidays
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class Timeloop extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * @var ?Timeloop The plugin instance.
     */
    public static ?Timeloop $plugin = null;

    // Public Properties
    // =========================================================================

    /**
     * To execute your plugin’s migrations, you’ll need to increase its schema version.
     *
     * @var string
     */
    public string $schemaVersion = '2.0.0';

    /**
     * Set to `true` if the plugin should have a settings view in the control panel.
     *
     * @var bool
     */
    public bool $hasCpSettings = false;

    /**
     * Set to `true` if the plugin should have its own section (main nav item) in the control panel.
     *
     * @var bool
     */
    public bool $hasCpSection = false;

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'timeloop' => TimeloopService::class,
                'holidays' => HolidaysService::class,
                'vite' => [
                    'class' => VitePluginService::class,
                    'assetClass' => TimeloopAsset::class,
                    'useDevServer' => true,
                    'devServerPublic' => 'http://localhost:3001',
                    'serverPublic' => 'http://localhost:8000',
                    'errorEntry' => '/src/js/timeloop.ts',
                    'devServerInternal' => 'http://craft-timeloop-buildchain:3001',
                    'checkDevServer' => true,
                ],
            ],
        ];
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // Register our fields
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function(RegisterComponentTypesEvent $event): void {
                $event->types[] = TimeloopField::class;
            }
        );

        // Register variable
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('timeloop', [
                    'class' => TimeloopVariable::class,
                    'viteService' => $this->vite,
                ]);
            }
        );

        // Add in our Twig extensions
        Craft::$app->getView()->registerTwigExtension(new TimeloopTwigExtension());

        Craft::info(
            Craft::t(
                'timeloop',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }
}
