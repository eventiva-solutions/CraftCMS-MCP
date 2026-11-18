<?php

namespace eventiva\mcp\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%mcp_tokens}}')) {
            $this->createTable('{{%mcp_tokens}}', [
                'id' => $this->primaryKey(),
                'name' => $this->string(100)->notNull(),
                'tokenHash' => $this->char(64)->notNull(),
                'tokenPrefix' => $this->string(16)->notNull(),
                'userId' => $this->integer()->notNull(),
                'scopes' => $this->string(100)->notNull()->defaultValue('read'),
                'allowedSections' => $this->text()->null(),
                'expiresAt' => $this->dateTime()->null(),
                'lastUsedAt' => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%mcp_tokens}}', ['tokenHash'], true);
            $this->createIndex(null, '{{%mcp_tokens}}', ['userId']);
            $this->addForeignKey(null, '{{%mcp_tokens}}', ['userId'], '{{%users}}', ['id'], 'CASCADE', 'CASCADE');
        }

        if (!$this->db->tableExists('{{%mcp_audit}}')) {
            $this->createTable('{{%mcp_audit}}', [
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
            $this->createIndex(null, '{{%mcp_audit}}', ['tokenId']);
            $this->createIndex(null, '{{%mcp_audit}}', ['dateCreated']);
            $this->addForeignKey(null, '{{%mcp_audit}}', ['tokenId'], '{{%mcp_tokens}}', ['id'], 'SET NULL', 'CASCADE');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%mcp_audit}}');
        $this->dropTableIfExists('{{%mcp_tokens}}');
        return true;
    }
}
