<?php
/**
 * Timeloop plugin for Craft CMS 4.x
 *
 * This is a plugin to make repeating dates
 *
 * @link      https://craftpulse.com
 * @copyright Copyright (c) 2024 CraftPulse
 */

namespace craftpulse\timelooptests\unit;

use Codeception\Test\Unit;
use UnitTester;
use Craft;
use craftpulse\timeloop\Timeloop;

/**
 * ExampleUnitTest
 *
 *
 * @author    craftpulse
 * @package   Timeloop
 * @since     0.1.0
 */
class ExampleUnitTest extends Unit
{
    // Properties
    // =========================================================================

    /**
     * @var UnitTester
     */
    protected $tester;

    // Public methods
    // =========================================================================

    // Tests
    // =========================================================================

    /**
     *
     */
    public function testPluginInstance()
    {
        $this->assertInstanceOf(
            Timeloop::class,
            Timeloop::$plugin
        );
    }

    /**
     *
     */
    public function testCraftEdition()
    {
        Craft::$app->setEdition(Craft::Pro);

        $this->assertSame(
            Craft::Pro,
            Craft::$app->getEdition()
        );
    }
}
