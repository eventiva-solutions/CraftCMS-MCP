<?php

namespace eventiva\mcp\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int|null $tokenId
 * @property string|null $tokenName
 * @property string $tool
 * @property string|null $arguments
 * @property bool $success
 * @property string|null $message
 * @property int|null $durationMs
 * @property string $dateCreated
 */
class AuditRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%mcp_audit}}';
    }
}
