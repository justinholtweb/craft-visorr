<?php

namespace justinholtweb\visorr\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\visorr\db\Table;

/**
 * Visorr's three tables.
 *
 * Deliberately small. Everything Visorr shows about a revision — its author, notes, number,
 * date, content — is read from Craft's own `revisions` and `elements` rows, which is what
 * lets the plugin work on history it did not create. These tables only hold the two facts
 * Craft never recorded (this revision is pinned; this revision was authored from that site)
 * and the ledger of what Visorr itself has deleted.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createPins();
        $this->createRevisionSites();
        $this->createPrunes();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::PRUNES);
        $this->dropTableIfExists(Table::REVISION_SITES);
        $this->dropTableIfExists(Table::PINS);

        return true;
    }

    private function createPins(): void
    {
        if ($this->db->tableExists(Table::PINS)) {
            return;
        }

        $this->createTable(Table::PINS, [
            'revisionId' => $this->integer()->notNull(),
            'label' => $this->string(255),
            'pinnedBy' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addPrimaryKey(null, Table::PINS, ['revisionId']);

        // A pin is meaningless once the revision is gone, and a revision row outliving its
        // pin row is the state that would let a prune quietly ignore a pin.
        $this->addForeignKey(null, Table::PINS, ['revisionId'], CraftTable::REVISIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::PINS, ['pinnedBy'], CraftTable::USERS, ['id'], 'SET NULL');
    }

    private function createRevisionSites(): void
    {
        if ($this->db->tableExists(Table::REVISION_SITES)) {
            return;
        }

        $this->createTable(Table::REVISION_SITES, [
            'revisionId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
        ]);

        $this->addPrimaryKey(null, Table::REVISION_SITES, ['revisionId']);
        $this->addForeignKey(null, Table::REVISION_SITES, ['revisionId'], CraftTable::REVISIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::REVISION_SITES, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE');
        $this->createIndex(null, Table::REVISION_SITES, ['siteId']);
    }

    private function createPrunes(): void
    {
        if ($this->db->tableExists(Table::PRUNES)) {
            return;
        }

        $this->createTable(Table::PRUNES, [
            'id' => $this->primaryKey(),
            'scope' => $this->string(32)->notNull(),
            'elementType' => $this->string(255),
            'sectionUid' => $this->string(36),
            'canonicalId' => $this->integer(),
            'siteId' => $this->integer(),
            'applied' => $this->boolean()->notNull()->defaultValue(false),
            'plannedCount' => $this->integer()->notNull()->defaultValue(0),
            'deletedCount' => $this->integer()->notNull()->defaultValue(0),
            'protectedCount' => $this->integer()->notNull()->defaultValue(0),
            'freedBytes' => $this->bigInteger()->notNull()->defaultValue(0),
            // The IDs the plan resolved, verbatim. Keeping them is what makes drift between
            // "what the preview said" and "what was deleted" visible after the fact.
            'plannedIds' => $this->mediumText(),
            'errors' => $this->text(),
            'triggeredBy' => $this->integer(),
            'trigger' => $this->string(32)->notNull()->defaultValue('cp'),
            'dateFinished' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey(null, Table::PRUNES, ['triggeredBy'], CraftTable::USERS, ['id'], 'SET NULL');
        $this->createIndex(null, Table::PRUNES, ['dateCreated']);
        $this->createIndex(null, Table::PRUNES, ['applied', 'dateCreated']);
    }
}
