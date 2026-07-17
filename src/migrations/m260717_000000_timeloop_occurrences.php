<?php
/**
 * Timeloop plugin for Craft CMS
 *
 * The timeloop plugin creates repeating dates without the need of complex inputs.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\timeloop\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Creates the occurrence index table on existing installs.
 *
 * The fresh-install counterpart of this schema lives in {@see Install}; the two
 * must stay in lockstep. The table is created idempotently (guarded by
 * `tableExists()`), so this migration is harmless to re-run and never collides
 * with a fresh install that already created the table via `Install::safeUp()`.
 *
 * The index is populated lazily: this migration only creates the (empty) table.
 * Existing entries are backfilled on their next save, by the nightly
 * `timeloop/occurrences/refresh` roll, or in one pass via
 * `timeloop/occurrences/rebuild`. Until a value is indexed the read path falls
 * back to live expansion, so an empty index never changes rendered output.
 *
 * @author CraftPulse
 * @since 5.1.0
 */
class m260717_000000_timeloop_occurrences extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if ($this->db->tableExists(Install::OCCURRENCES_TABLE)) {
            return true;
        }

        $this->createTable(Install::OCCURRENCES_TABLE, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'fieldHandle' => $this->string()->notNull(),
            'occurrenceStart' => $this->dateTime()->notNull(),
            'occurrenceEnd' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Install::OCCURRENCES_TABLE, ['fieldHandle', 'occurrenceStart', 'occurrenceEnd']);
        $this->createIndex(null, Install::OCCURRENCES_TABLE, ['elementId', 'siteId', 'fieldHandle', 'occurrenceStart']);

        $this->addForeignKey(null, Install::OCCURRENCES_TABLE, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Install::OCCURRENCES_TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE', null);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Install::OCCURRENCES_TABLE);

        return true;
    }
}
