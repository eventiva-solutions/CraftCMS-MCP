<?php

namespace eventiva\mcp\tools\write;

use Craft;
use craft\elements\Entry;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\Serializes;
use eventiva\mcp\tools\Writes;

class PublishDraft extends AbstractTool
{
    use Serializes, Writes;

    public function name(): string
    {
        return 'publish_draft';
    }

    public function description(): string
    {
        return 'Applies a draft (from create_draft) to its live entry.';
    }

    public function scope(): string
    {
        return 'write';
    }

    public function properties(): array
    {
        return ['draftElementId' => ['type' => 'integer', 'description' => '"draftElementId" returned by create_draft.']];
    }

    public function required(): array
    {
        return ['draftElementId'];
    }

    public function run(array $args): mixed
    {
        /** @var Entry|null $draft */
        $draft = Entry::find()->id((int)$args['draftElementId'])->drafts(true)->status(null)->one();
        if (!$draft || !$draft->getIsDraft()) {
            throw new \InvalidArgumentException("Draft {$args['draftElementId']} not found.");
        }
        if ($section = $draft->getSection()) {
            $this->assertSectionAllowed($section->handle);
        }
        $this->assertCanSave($draft);

        /** @var Entry $live */
        $live = Craft::$app->getDrafts()->applyDraft($draft);
        return $this->entrySummary($live);
    }
}
