<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craftpulse\timeloop\services\OccurrenceIndexService;
use craftpulse\timeloop\Timeloop;
use Throwable;
use yii\base\InvalidConfigException;
use yii\console\ExitCode;

/**
 * Maintains the Timeloop occurrence index from the command line.
 *
 * Thin controller: every action delegates to {@see OccurrenceIndexService}.
 *
 * - `timeloop/occurrences/refresh` rolls the expansion horizon forward by
 *   re-expanding every already-indexed value in place (no truncation window),
 *   picking up occurrences the moving horizon and freshly-resolved future-year
 *   holidays now reach. Intended for a nightly cron, e.g.
 *   `0 3 * * * cd /path/to/project && php craft timeloop/occurrences/refresh`.
 * - `timeloop/occurrences/rebuild` truncates the index and rebuilds it from
 *   scratch, for a one-off repair after a bulk import or a horizon setting
 *   change.
 *
 * @author CraftPulse
 * @since 5.1.0
 */
class OccurrencesController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Rolls the occurrence index horizon forward (re-expands indexed values in place).
     *
     * @return int
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function actionRefresh(): int
    {
        return $this->_run(
            'Refreshing the Timeloop occurrence index',
            fn(callable $progress): int => $this->_index()->refresh($progress),
        );
    }

    /**
     * Truncates and rebuilds the occurrence index from scratch.
     *
     * @return int
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    public function actionRebuild(): int
    {
        return $this->_run(
            'Rebuilding the Timeloop occurrence index',
            fn(callable $progress): int => $this->_index()->rebuild($progress),
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Runs an index operation with progress output and an exit code.
     *
     * @param string $label The human-readable operation label.
     * @param callable $operation A `fn(callable $progress): int` returning the number of elements processed.
     * @return int
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _run(string $label, callable $operation): int
    {
        $this->stdout("$label ...\n");

        try {
            $count = $operation(function(int $done): void {
                $this->stdout("\r    > reindexed $done element(s)");
            });
        } catch (Throwable $e) {
            $this->stderr("\n" . $e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n");
        $this->stdout("Done. Reindexed $count element(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Returns the occurrence index service.
     *
     * @return OccurrenceIndexService
     * @throws \yii\base\InvalidConfigException if the plugin or component is misconfigured.
     *
     * @author CraftPulse
     * @since 5.1.0
     */
    private function _index(): OccurrenceIndexService
    {
        $plugin = Timeloop::getInstance();

        if ($plugin === null) {
            throw new InvalidConfigException('The Timeloop plugin is not installed.');
        }

        return $plugin->getOccurrenceIndex();
    }
}
