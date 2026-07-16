<?php
/**
 * Timeloop plugin for Craft CMS 5.x
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) CraftPulse
 */

/**
 * Pest bootstrap for the Timeloop test suite.
 *
 * Phase 0 keeps this harness deliberately minimal: tests run standalone via
 * `vendor/bin/pest` without booting a Craft application. Real suites (recurrence
 * engine, storage migration, holidays) arrive in later phases and will extend
 * this file with the test cases and helpers they need.
 *
 * @author CraftPulse
 * @since 5.1.0
 */

uses()->in(__DIR__);
