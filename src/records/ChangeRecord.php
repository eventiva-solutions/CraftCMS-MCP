<?php

namespace eventiva\mcp\records;

use Craft;
use craft\db\ActiveRecord;

/**
 * One item of the approval queue.
 *
 * @property int $id
 * @property int|null $tokenId
 * @property string|null $tokenName
 * @property string $type entry_create | entry_update | entry_delete | asset_upload | asset_delete | term_delete
 * @property int|null $elementId   draft / asset / entry the change is about
 * @property int|null $canonicalId live entry a draft belongs to
 * @property int|null $resultId    live element after approval
 * @property string|null $label
 * @property string|null $section
 * @property string $status pending | approved | rejected | failed
 * @property string|null $summary JSON
 * @property string|null $note
 * @property string|null $batch
 * @property int|null $decidedBy
 * @property string|null $decidedAt
 * @property string $dateCreated
 */
class ChangeRecord extends ActiveRecord
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const FAILED = 'failed';

    public static function tableName(): string
    {
        return '{{%mcpserver_changes}}';
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'entry_create' => Craft::t('mcp-server', 'New entry'),
            'entry_update' => Craft::t('mcp-server', 'Change'),
            'entry_delete' => Craft::t('mcp-server', 'Delete'),
            'asset_upload' => Craft::t('mcp-server', 'Upload'),
            'asset_delete' => Craft::t('mcp-server', 'Delete asset'),
            'term_delete' => Craft::t('mcp-server', 'Delete category/tag'),
            default => $this->type,
        };
    }

    public function summaryData(): array
    {
        $data = $this->summary ? json_decode($this->summary, true) : null;
        return is_array($data) ? $data : [];
    }
}
