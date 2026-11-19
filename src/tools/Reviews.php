<?php

namespace eventiva\mcp\tools;

use Craft;
use craft\elements\Entry;
use eventiva\mcp\records\ChangeRecord;

/**
 * Helpers for tokens that require approval: changes go into drafts / the approval queue instead of live content.
 */
trait Reviews
{
    /**
     * Returns the draft an entry change must be applied to (never the live entry), plus the queue item that
     * already exists for it. Reuses the pending draft of a live entry instead of creating a second one.
     *
     * @return array{0: Entry, 1: ?ChangeRecord}
     */
    protected function resolveReviewDraft(Entry $entry): array
    {
        $review = $this->review();

        if ($entry->getIsDraft()) {
            $change = $review->findPending(['entry_create', 'entry_update'], $entry->id);
            if (!$change) {
                throw new \RuntimeException('This draft was not created by your token, so it cannot be edited while approval is required.');
            }
            return [$entry, $change];
        }

        if ($change = $review->findPending(['entry_update'], null, $entry->id)) {
            $draft = Entry::find()->id((int)$change->elementId)->drafts(true)->status(null)->one();
            if ($draft) {
                return [$draft, $change];
            }
        }

        /** @var Entry $draft */
        $draft = Craft::$app->getDrafts()->createDraft(
            $entry,
            $this->user()->id,
            'MCP: ' . mb_strimwidth((string)$entry->title, 0, 60, '…'),
            'Created via MCP, waiting for approval'
        );
        return [$draft, null];
    }

    /** Makes sure the draft is listed in the approval queue. */
    protected function registerReviewChange(Entry $draft, ?ChangeRecord $existing, ?Entry $canonical): ChangeRecord
    {
        if ($existing) {
            return $existing;
        }
        return $this->review()->queue(
            $canonical ? 'entry_update' : 'entry_create',
            $draft->id,
            $canonical?->id,
            (string)($draft->title ?: $canonical?->title),
            $draft->getSection()?->handle,
            ['entryType' => $draft->getType()->handle]
        );
    }

    /** The part of a response that tells the model nothing is live yet. */
    protected function pendingInfo(ChangeRecord $change, string $message, array $extra = []): array
    {
        return [
            'pendingApproval' => true,
            'changeId' => $change->id,
            'message' => $message,
        ] + $extra;
    }
}
