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

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%mcpserver_audit}}');
        $this->dropTableIfExists('{{%mcpserver_tokens}}');
        return true;
    }
}
