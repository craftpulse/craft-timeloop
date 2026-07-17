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
 * Install migration.
 *
 * Creates the occurrence index table (`{{%timeloop_occurrences}}`) on a fresh
 * plugin install. The exact same schema is created for existing installs by
 * {@see m260717_000000_timeloop_occurrences}; the two must stay in lockstep (see
 * the migrations house rule: every schema change lands in both `Install.php` and
 * a dated migration).
 *
 * The index is a denormalized, horizon-bounded expansion of every Timeloop field
 * value: one row per occurrence `[occurrenceStart, occurrenceEnd)` window, keyed
 * by the element carrying the field, its site and the field handle. It backs the
 * cheap element-query params ({@see \craftpulse\timeloop\behaviors\TimeloopQueryBehavior})
 * without expanding any recurrence at query time. Rows are pure runtime data (not
 * project config): they are rebuilt on element save and by the
 * `timeloop/occurrences/*` console commands.
 *
 * @author CraftPulse
 * @since 5.1.0
 */
class Install extends Migration
{
    // Constants
    // =========================================================================

    /**
     * @var string The occurrence index table name.
     */
    public const OCCURRENCES_TABLE = '{{%timeloop_occurrences}}';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::OCCURRENCES_TABLE)) {
            return true;
        }

        $this->createTable(self::OCCURRENCES_TABLE, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'fieldHandle' => $this->string()->notNull(),
            'occurrenceStart' => $this->dateTime()->notNull(),
            'occurrenceEnd' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Range scans for the query behavior's timeloopBetween()/orderBy('timeloopNext').
        $this->createIndex(null, self::OCCURRENCES_TABLE, ['fieldHandle', 'occurrenceStart', 'occurrenceEnd']);

        // Per-element lookups for indexElement() rewrites, activeTimeloop() EXISTS
        // checks and the next-occurrence subquery. Also satisfies the elementId FK's
        // index requirement (leftmost prefix).
        $this->createIndex(null, self::OCCURRENCES_TABLE, ['elementId', 'siteId', 'fieldHandle', 'occurrenceStart']);

        $this->addForeignKey(null, self::OCCURRENCES_TABLE, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, self::OCCURRENCES_TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE', null);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::OCCURRENCES_TABLE);

        return true;
    }
}
