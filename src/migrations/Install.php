<?php

namespace eventiva\mcp\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%mcpserver_tokens}}')) {
            $this->createTable('{{%mcpserver_tokens}}', [
                'id' => $this->primaryKey(),
                'name' => $this->string(100)->notNull(),
                'tokenHash' => $this->char(64)->notNull(),
                'tokenPrefix' => $this->string(16)->notNull(),
                'userId' => $this->integer()->notNull(),
                'scopes' => $this->string(100)->notNull()->defaultValue('read'),
                'allowedSections' => $this->text()->null(),
                'requireReview' => $this->boolean()->notNull()->defaultValue(false),
                'expiresAt' => $this->dateTime()->null(),
                'lastUsedAt' => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%mcpserver_tokens}}', ['tokenHash'], true);
            $this->createIndex(null, '{{%mcpserver_tokens}}', ['userId']);
            $this->addForeignKey(null, '{{%mcpserver_tokens}}', ['userId'], '{{%users}}', ['id'], 'CASCADE', 'CASCADE');
        }

        if (!$this->db->tableExists('{{%mcpserver_audit}}')) {
            $this->createTable('{{%mcpserver_audit}}', [
                'id' => $this->primaryKey(),
                'tokenId' => $this->integer()->null(),
                'tokenName' => $this->string(100)->null(),
                'tool' => $this->string(100)->notNull(),
                'arguments' => $this->text()->null(),
                'success' => $this->boolean()->notNull()->defaultValue(true),
                'message' => $this->text()->null(),
                'durationMs' => $this->integer()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%mcpserver_audit}}', ['tokenId']);
            $this->createIndex(null, '{{%mcpserver_audit}}', ['dateCreated']);
            $this->addForeignKey(null, '{{%mcpserver_audit}}', ['tokenId'], '{{%mcpserver_tokens}}', ['id'], 'SET NULL', 'CASCADE');
        }

        self::createChangesTable($this);

        return true;
    }

    /**
     * The approval queue. Shared with the "add review" migration so fresh and upgraded installs end up identical.
     */
    public static function createChangesTable(Migration $migration): void
    {
        $db = $migration->db;
        if ($db->tableExists('{{%mcpserver_changes}}')) {
            return;
        }

        $migration->createTable('{{%mcpserver_changes}}', [
            'id' => $migration->primaryKey(),
            'tokenId' => $migration->integer()->null(),
            'tokenName' => $migration->string(100)->null(),
            'type' => $migration->string(30)->notNull(),          // entry_create, entry_update, entry_delete, asset_upload, asset_delete, term_delete
            'elementId' => $migration->integer()->null(),          // the draft / asset / entry the change is about
            'canonicalId' => $migration->integer()->null(),        // the live entry a draft belongs to
            'resultId' => $migration->integer()->null(),           // the live element after approval
            'label' => $migration->string(255)->null(),
            'section' => $migration->string(100)->null(),
            'status' => $migration->string(20)->notNull()->defaultValue('pending'), // pending, approved, rejected, failed
            'summary' => $migration->text()->null(),
            'note' => $migration->text()->null(),
            'batch' => $migration->string(100)->null(),
            'decidedBy' => $migration->integer()->null(),
            'decidedAt' => $migration->dateTime()->null(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);
        $migration->createIndex(null, '{{%mcpserver_changes}}', ['status']);
        $migration->createIndex(null, '{{%mcpserver_changes}}', ['tokenId']);
        $migration->createIndex(null, '{{%mcpserver_changes}}', ['elementId']);
        $migration->addForeignKey(null, '{{%mcpserver_changes}}', ['tokenId'], '{{%mcpserver_tokens}}', ['id'], 'SET NULL', 'CASCADE');
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%mcpserver_changes}}');
        $this->dropTableIfExists('{{%mcpserver_audit}}');
        $this->dropTableIfExists('{{%mcpserver_tokens}}');
        return true;
    }
}
