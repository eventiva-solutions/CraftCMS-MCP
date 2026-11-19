<?php

namespace eventiva\mcp\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\helpers\Db;
use eventiva\mcp\helpers\DiffHelper;
use eventiva\mcp\Mcp;
use eventiva\mcp\records\ChangeRecord;

/**
 * The approval queue: tokens with "requireReview" never change live content directly.
 * Their changes are stored as Craft drafts (or as deletion requests) and listed here
 * until a human approves or rejects them in the control panel.
 */
class ReviewService extends Component
{
    /** Craft user permission that allows approving and rejecting changes. Admins always may. */
    public const PERMISSION = 'mcpserver-approve';

    /** Changes of one token within this many minutes are shown as one batch. */
    private const BATCH_GAP_MINUTES = 30;

    public function requiresReview(): bool
    {
        return (bool)Mcp::getInstance()->tokens->current?->requireReview;
    }

    // -- queueing (called by the tools) --------------------------------------------------

    public function queue(string $type, ?int $elementId, ?int $canonicalId, string $label, ?string $section = null, array $summary = []): ChangeRecord
    {
        $token = Mcp::getInstance()->tokens->current;

        $record = new ChangeRecord();
        $record->tokenId = $token?->id;
        $record->tokenName = $token?->name;
        $record->type = $type;
        $record->elementId = $elementId;
        $record->canonicalId = $canonicalId;
        $record->label = mb_substr($label, 0, 255);
        $record->section = $section;
        $record->status = ChangeRecord::PENDING;
        $record->summary = $summary ? json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $record->batch = $this->batchFor($token?->id, $token?->name);
        $record->save(false);

        return $record;
    }

    /** Finds a pending change of the current token, e.g. to reuse an existing draft instead of creating another one. */
    public function findPending(array $types, ?int $elementId = null, ?int $canonicalId = null): ?ChangeRecord
    {
        $query = ChangeRecord::find()->where(['status' => ChangeRecord::PENDING, 'type' => $types]);
        if ($tokenId = Mcp::getInstance()->tokens->current?->id) {
            $query->andWhere(['tokenId' => $tokenId]);
        }
        if ($elementId !== null) {
            $query->andWhere(['elementId' => $elementId]);
        }
        if ($canonicalId !== null) {
            $query->andWhere(['canonicalId' => $canonicalId]);
        }
        /** @var ChangeRecord|null $record */
        $record = $query->orderBy(['id' => SORT_DESC])->one();
        return $record;
    }

    private function batchFor(?int $tokenId, ?string $tokenName): string
    {
        $since = Db::prepareDateForDb(new \DateTime('-' . self::BATCH_GAP_MINUTES . ' minutes'));
        $last = ChangeRecord::find()->where(['tokenId' => $tokenId])->andWhere(['>', 'dateCreated', $since])->orderBy(['id' => SORT_DESC])->one();
        if ($last && $last->batch) {
            return $last->batch;
        }
        return ($tokenName ?: 'MCP') . ' · ' . date('d.m.Y H:i');
    }

    // -- listing --------------------------------------------------------------------------

    public function pendingCount(): int
    {
        try {
            return (int)ChangeRecord::find()->where(['status' => ChangeRecord::PENDING])->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return ChangeRecord[] */
    public function listChanges(string $status = ChangeRecord::PENDING, int $limit = 300): array
    {
        $query = ChangeRecord::find()->orderBy(['id' => SORT_DESC])->limit($limit);
        if ($status !== 'all') {
            $query->where(['status' => $status]);
        }
        return $query->all();
    }

    /** @return ChangeRecord[] */
    public function listForToken(int $tokenId, string $status = 'all', int $limit = 50): array
    {
        $query = ChangeRecord::find()->where(['tokenId' => $tokenId])->orderBy(['id' => SORT_DESC])->limit($limit);
        if ($status !== 'all') {
            $query->andWhere(['status' => $status]);
        }
        return $query->all();
    }

    public function canApprove(?User $user = null): bool
    {
        $user ??= Craft::$app->getUser()->getIdentity();
        return $user instanceof User && ($user->admin || $user->can(self::PERMISSION));
    }

    // -- deciding ---------------------------------------------------------------------------

    /** @return array{0: bool, 1: string} */
    public function approve(ChangeRecord $change): array
    {
        if ($change->status !== ChangeRecord::PENDING) {
            return [false, Craft::t('mcp-server', 'Already decided.')];
        }
        $user = Craft::$app->getUser()->getIdentity();
        $message = Craft::t('mcp-server', 'Approved');

        try {
            switch ($change->type) {
                case 'entry_create':
                case 'entry_update':
                    $draft = $this->findDraft((int)$change->elementId);
                    if (!$draft) {
                        throw new \RuntimeException(Craft::t('mcp-server', 'The draft no longer exists.'));
                    }
                    if (!Craft::$app->getElements()->canSave($draft, $user)) {
                        throw new \RuntimeException(Craft::t('mcp-server', 'You are not allowed to save this entry.'));
                    }
                    /** @var Entry $live */
                    $live = Craft::$app->getDrafts()->applyDraft($draft);
                    $change->resultId = $live->id;
                    Craft::$app->getSearch()->indexElementAttributes($live);
                    $message = $change->type === 'entry_create' ? Craft::t('mcp-server', 'Entry created') : Craft::t('mcp-server', 'Change applied');
                    break;

                case 'entry_delete':
                case 'asset_delete':
                case 'term_delete':
                    $element = $this->findElement($change);
                    if ($element) {
                        if (!Craft::$app->getElements()->canDelete($element, $user)) {
                            throw new \RuntimeException(Craft::t('mcp-server', 'You are not allowed to delete this.'));
                        }
                        if (!Craft::$app->getElements()->deleteElement($element)) {
                            throw new \RuntimeException(Craft::t('mcp-server', 'Deletion failed.'));
                        }
                        $message = Craft::t('mcp-server', 'Moved to the trash');
                    } else {
                        $message = Craft::t('mcp-server', 'Was already deleted');
                    }
                    break;

                case 'asset_upload':
                    $message = Craft::t('mcp-server', 'Upload confirmed');
                    break;

                default:
                    throw new \RuntimeException(Craft::t('mcp-server', 'Unknown change type: {type}', ['type' => $change->type]));
            }
            $change->status = ChangeRecord::APPROVED;
        } catch (\Throwable $e) {
            Craft::warning('MCP review approve failed: ' . $e->getMessage(), __METHOD__);
            $change->status = ChangeRecord::FAILED;
            $change->note = $e->getMessage();
            $message = $e->getMessage();
        }

        $this->finish($change, $user);
        return [$change->status === ChangeRecord::APPROVED, $message];
    }

    /** @return array{0: bool, 1: string} */
    public function reject(ChangeRecord $change, ?string $note = null): array
    {
        if ($change->status !== ChangeRecord::PENDING) {
            return [false, Craft::t('mcp-server', 'Already decided.')];
        }
        $user = Craft::$app->getUser()->getIdentity();

        try {
            switch ($change->type) {
                case 'entry_create':
                case 'entry_update':
                    if ($draft = $this->findDraft((int)$change->elementId)) {
                        Craft::$app->getElements()->deleteElement($draft, true);
                    }
                    break;
                case 'asset_upload':
                    $asset = Asset::find()->id((int)$change->elementId)->status(null)->one();
                    if ($asset) {
                        Craft::$app->getElements()->deleteElement($asset);
                    }
                    break;
                default:
                    // deletion requests: nothing was deleted, so there is nothing to undo
                    break;
            }
            $change->status = ChangeRecord::REJECTED;
            $change->note = $note !== null && trim($note) !== '' ? trim($note) : null;
            $message = Craft::t('mcp-server', 'Rejected');
        } catch (\Throwable $e) {
            Craft::warning('MCP review reject failed: ' . $e->getMessage(), __METHOD__);
            $change->status = ChangeRecord::FAILED;
            $change->note = $e->getMessage();
            $message = $e->getMessage();
        }

        $this->finish($change, $user);
        return [$change->status === ChangeRecord::REJECTED, $message];
    }

    private function finish(ChangeRecord $change, ?User $user): void
    {
        $change->decidedBy = $user?->id;
        $change->decidedAt = Db::prepareDateForDb(new \DateTime());
        $change->save(false);

        Mcp::getInstance()->audit->log(
            null,
            'review.' . ($change->status === ChangeRecord::APPROVED ? 'approve' : ($change->status === ChangeRecord::REJECTED ? 'reject' : 'failed')),
            ['changeId' => $change->id, 'type' => $change->type, 'label' => $change->label, 'by' => $user?->username],
            $change->status !== ChangeRecord::FAILED,
            (string)($change->note ?? $change->status),
            0
        );
    }

    // -- presenting a change ------------------------------------------------------------------

    /**
     * Everything the review page needs to show one change.
     *
     * @return array{title: string, typeLabel: string, section: ?string, editUrl: ?string, liveUrl: ?string, rows: array, warnings: string[], thumb: ?string, details: string[]}
     */
    public function present(ChangeRecord $change): array
    {
        $info = [
            'title' => (string)$change->label,
            'typeLabel' => $change->typeLabel(),
            'section' => $change->section,
            'editUrl' => null,
            'liveUrl' => null,
            'rows' => [],
            'warnings' => [],
            'thumb' => null,
            'details' => [],
        ];

        try {
            switch ($change->type) {
                case 'entry_create':
                case 'entry_update':
                    $draft = $this->findDraft((int)$change->elementId);
                    if (!$draft) {
                        $info['warnings'][] = Craft::t('mcp-server', 'The draft no longer exists.');
                        break;
                    }
                    $canonical = $change->canonicalId ? Entry::find()->id((int)$change->canonicalId)->status(null)->one() : null;
                    $info['editUrl'] = $draft->getCpEditUrl();
                    $info['liveUrl'] = $canonical?->getUrl();
                    $info['rows'] = $this->entryRows($canonical, $draft);

                    if ($canonical && $canonical->dateUpdated && $canonical->dateUpdated->getTimestamp() > strtotime($change->dateCreated . ' UTC')) {
                        $info['warnings'][] = Craft::t('mcp-server', 'The live entry was changed after the draft was created ({date}). Approving overwrites those changes.', ['date' => Craft::$app->getFormatter()->asDatetime($canonical->dateUpdated, 'short')]);
                    }
                    if ($change->type === 'entry_create' && !$draft->enabled) {
                        $info['details'][] = Craft::t('mcp-server', 'The new entry is disabled and stays disabled after approval until it is enabled.');
                    }
                    break;

                case 'entry_delete':
                case 'asset_delete':
                case 'term_delete':
                    $element = $this->findElement($change);
                    if (!$element) {
                        $info['warnings'][] = Craft::t('mcp-server', 'The element no longer exists.');
                        break;
                    }
                    $info['editUrl'] = $element->getCpEditUrl();
                    $info['liveUrl'] = $element->getUrl();
                    $info['details'][] = Craft::t('mcp-server', 'Approving moves the element to the trash.');
                    $data = $change->summaryData();
                    if (!empty($data['filename'])) {
                        $info['details'][] = Craft::t('mcp-server', 'Filename: {value}', ['value' => $data['filename']]);
                    }
                    if (!empty($data['section'])) {
                        $info['details'][] = Craft::t('mcp-server', 'Section: {value}', ['value' => $data['section']]);
                    }
                    if (!empty($data['group'])) {
                        $info['details'][] = Craft::t('mcp-server', 'Group: {value}', ['value' => $data['group']]);
                    }
                    if ($element instanceof Asset && $element->kind === 'image') {
                        $info['thumb'] = $element->getUrl();
                    }
                    break;

                case 'asset_upload':
                    $asset = Asset::find()->id((int)$change->elementId)->status(null)->one();
                    if (!$asset) {
                        $info['warnings'][] = Craft::t('mcp-server', 'The asset no longer exists.');
                        break;
                    }
                    $info['editUrl'] = $asset->getCpEditUrl();
                    $info['thumb'] = $asset->kind === 'image' ? $asset->getUrl() : null;
                    $info['details'][] = $asset->filename . ' · ' . Craft::$app->getFormatter()->asShortSize((int)$asset->size) . ($asset->width ? ' · ' . $asset->width . '×' . $asset->height : '');
                    $info['details'][] = Craft::t('mcp-server', 'The file is already uploaded. Rejecting it deletes the file.');
                    break;
            }
        } catch (\Throwable $e) {
            $info['warnings'][] = Craft::t('mcp-server', 'Preview not possible: {error}', ['error' => $e->getMessage()]);
        }

        return $info;
    }

    /** @return array<int, array{field: string, label: string, html: string}> */
    private function entryRows(?Entry $canonical, Entry $draft): array
    {
        $labels = ['title' => Craft::t('mcp-server', 'Title'), 'slug' => Craft::t('mcp-server', 'Slug'), 'enabled' => Craft::t('mcp-server', 'Active'), 'postDate' => Craft::t('mcp-server', 'Post date')];
        foreach ($draft->getFieldLayout()?->getCustomFieldElements() ?? [] as $element) {
            $labels[$element->getField()->handle] = $element->label ?: $element->getField()->name;
        }

        $base = fn(Entry $e) => [
            'title' => $e->title,
            'slug' => $e->slug,
            'enabled' => (bool)$e->enabled,
            'postDate' => $e->postDate?->format('d.m.Y H:i'),
        ];

        $old = $canonical ? $base($canonical) + $canonical->getSerializedFieldValues() : [];
        $new = $base($draft) + $draft->getSerializedFieldValues();

        return DiffHelper::compare($old, $new, $labels);
    }

    // -- lookups ----------------------------------------------------------------------------------

    private function findDraft(int $id): ?Entry
    {
        /** @var Entry|null $draft */
        $draft = Entry::find()->id($id)->drafts(true)->status(null)->one();
        return $draft;
    }

    private function findElement(ChangeRecord $change): ?ElementInterface
    {
        $id = (int)$change->elementId;
        return match ($change->type) {
            'entry_delete' => Entry::find()->id($id)->status(null)->one(),
            'asset_delete' => Asset::find()->id($id)->status(null)->one(),
            'term_delete' => (($change->summaryData()['elementType'] ?? 'category') === 'tag' ? Tag::find() : Category::find())->id($id)->status(null)->one(),
            default => null,
        };
    }
}
