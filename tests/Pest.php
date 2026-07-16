<?php
/**
 * Timeloop plugin for Craft CMS 5.x
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) CraftPulse
 */

// =========================================================================
// PEST BOOTSTRAP
// =========================================================================
// Tests run standalone via `vendor/bin/pest` without booting a Craft app.
// Plain Pest test files stay unnamespaced; any PHP *class* added under
// tests/ (TestCase subclass, fixture builder) must declare the
// `craftpulse\timeloop\tests` namespace to match composer's autoload-dev.

uses()->in(__DIR__);
