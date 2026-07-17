<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\jobs;

use Craft;
use craft\queue\BaseJob;
use craftpulse\timeloop\Timeloop;

/**
 * Reindexes an element's Timeloop occurrences across every site.
 *
 * Queued from {@see \craftpulse\timeloop\services\OccurrenceIndexService::handleElementSave()}
 * when a saved value expands to more than
 * {@see \craftpulse\timeloop\services\OccurrenceIndexService::INLINE_THRESHOLD}
 * occurrences, so the save request never blocks on a large expansion or insert.
 *
 * The element is loaded with `site('*')` because queue workers run in the primary
 * site's context (house rule): resolving every site instance here guarantees each
 * site's slice is rewritten from its own per-site value, not just the primary's.
 * Disabled elements are included (`status(null)`); drafts and revisions are
 * filtered out by the index service's own eligibility guard.
 *
 * @author CraftPulse
 * @since 5.1.0
 */
class ReindexOccurrences extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var ?int The canonical element ID to reindex.
     */
    public ?int $elementId = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @throws \Throwable if an element cannot be re-indexed (see
     * {@see \craftpulse\timeloop\services\OccurrenceIndexService::indexElement()}).
     */
    public function execute($queue): void
    {
        if ($this->elementId === null) {
            return;
        }

        $canonical = Craft::$app->getElements()->getElementById($this->elementId);

        if ($canonical === null) {
            return;
        }

        $elementType = $canonical::class;

        /** @var \craft\base\ElementInterface[] $elements */
        $elements = $elementType::find()
            ->id($this->elementId)
            ->site('*')
            ->status(null)
            ->all();

        $index = Timeloop::getInstance()?->getOccurrenceIndex();

        if ($index === null) {
            return;
        }

        $total = count($elements);

        foreach ($elements as $i => $element) {
            $this->setProgress($queue, $total > 0 ? ($i + 1) / $total : 1, Craft::t('timeloop', 'Reindexing occurrences'));
            $index->indexElement($element);
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('timeloop', 'Reindexing Timeloop occurrences');
    }
}
