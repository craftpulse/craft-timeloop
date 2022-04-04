<?php
/**
 * Timeloop plugin for Craft CMS 3.x
 *
 * This is a plugin to make repeating dates
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2021 percipiolondon
 */

namespace percipiolondon\timeloop;

use percipiolondon\timeloop\assetbundles\timeloop\TimeloopAsset;
use percipiolondon\timeloop\models\SettingsModel;
use percipiolondon\timeloop\fields\TimeloopField;
use percipiolondon\timeloop\variables\TimeloopVariable;
use percipiolondon\timeloop\services\TimeloopService;

use nystudio107\pluginvite\services\VitePluginService;

use Craft;
use craft\base\Plugin;
use craft\services\Fields;
use craft\events\RegisterComponentTypesEvent;
use craft\web\twig\variables\CraftVariable;

use yii\base\Event;

/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://docs.craftcms.com/v3/extend/
 *
 * @author    percipiolondon
 * @package   Timeloop
 * @since     1.0.0
 *
 * @property  Settings $settings
 * @method    Settings getSettings()
 */
class Timeloop extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * @var Timeloop|null
     */
    public static ?Timeloop $plugin;

    /**
     * @var null|TimeloopVariable
     */
    public static ?TimeloopVariable $timeloopVariable = null;

    // Public Properties
    // =========================================================================

    /**
     * To execute your plugin’s migrations, you’ll need to increase its schema version.
     *
     * @var string
     */
    public string $schemaVersion = '1.0.0';

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

    public function __construct($id, $parent = null, array $config = [])
    {
        $config['components'] = [
            'timeloop' => Timeloop::class,
            'vite' => [
                'class' => VitePluginService::class,
                'assetClass' => TimeloopAsset::class,
                'useDevServer' => true,
                'devServerPublic' => 'http://localhost:3001',
                'serverPublic' => 'http://localhost:8000',
                'errorEntry' => '/src/js/timeloop.ts',
                'devServerInternal' => 'http://craft-timeloop-buildchain:3001',
                'checkDevServer' => true,
            ]
        ];

        parent::__construct($id, $parent, $config);
    }

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * Timeloop::$plugin
     *
     * Called after the plugin class is instantiated; do any one-time initialization
     * here such as hooks and events.
     *
     * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
     * you do not need to load it in your init() method.
     *
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // Register our fields
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = TimeloopField::class;
            }
        );

        // Register variable
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function (Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('timeloop', [
                'class' => TimeloopVariable::class,
                'viteService' => $this->vite,
            ]);
        });

        // Register services as components
        $this->setComponents([
            'timeloop' => TimeloopService::class,
        ]);

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
     * Creates and returns the model used to store the plugin’s settings.
     *
     * @return \craft\base\Model|null
     */
    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new SettingsModel();
    }

}
