<?php

namespace eventiva\mcp\tools\read;

use eventiva\mcp\Mcp;
use eventiva\mcp\records\ChangeRecord;
use eventiva\mcp\tools\AbstractTool;

class ListMyChanges extends AbstractTool
{
    public function name(): string
    {
        return 'list_my_changes';
    }

    public function description(): string
    {
        return 'Lists the changes made with this token that went through human approval: status (pending, approved, rejected, failed), the rejection reason and the draftElementId. Use it to see what was decided and to revise rejected work. Only relevant for tokens that require approval.';
    }

    public function properties(): array
    {
        return [
            'status' => ['type' => 'string', 'enum' => ['all', 'pending', 'approved', 'rejected', 'failed'], 'description' => 'Default: all.'],
            'limit' => ['type' => 'integer', 'description' => 'Max 100, default 30.'],
        ];
    }

    public function run(array $args): mixed
    {
        $token = Mcp::getInstance()->tokens->current;
        if (!$token) {
            return [];
        }
        $status = (string)($args['status'] ?? 'all');
        if (!in_array($status, ['all', 'pending', 'approved', 'rejected', 'failed'], true)) {
            throw new \InvalidArgumentException('status must be all, pending, approved, rejected or failed.');
        }

        $out = [
            'requiresApproval' => (bool)$token->requireReview,
            'changes' => [],
        ];
        foreach ($this->review()->listForToken((int)$token->id, $status, $this->limit($args, 30)) as $change) {
            $isDraftChange = in_array($change->type, ['entry_create', 'entry_update'], true);
            $out['changes'][] = array_filter([
                'changeId' => $change->id,
                'type' => $change->type,
                'label' => $change->label,
                'section' => $change->section,
                'status' => $change->status,
                'reason' => $change->status === ChangeRecord::REJECTED ? ($change->note ?: '(no reason given)') : ($change->status === ChangeRecord::FAILED ? $change->note : null),
                'draftElementId' => $isDraftChange && $change->status === ChangeRecord::PENDING ? $change->elementId : null,
                'liveEntryId' => $change->status === ChangeRecord::APPROVED ? ($change->resultId ?: $change->canonicalId) : $change->canonicalId,
                'created' => $change->dateCreated,
                'decided' => $change->decidedAt,
            ], fn($v) => $v !== null);
        }
        return $out;
    }
}
