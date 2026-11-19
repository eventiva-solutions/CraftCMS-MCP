<?php

namespace eventiva\mcp\migrations;

use craft\db\Migration;

/**
 * Adds the approval workflow: a "requireReview" flag on tokens and the change queue.
 */
class m261119_000000_add_review extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%mcpserver_tokens}}') && !$this->db->columnExists('{{%mcpserver_tokens}}', 'requireReview')) {
            $this->addColumn('{{%mcpserver_tokens}}', 'requireReview', $this->boolean()->notNull()->defaultValue(false)->after('allowedSections'));
        }

        Install::createChangesTable($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%mcpserver_changes}}');
        if ($this->db->columnExists('{{%mcpserver_tokens}}', 'requireReview')) {
            $this->dropColumn('{{%mcpserver_tokens}}', 'requireReview');
        }
        return true;
    }
}
