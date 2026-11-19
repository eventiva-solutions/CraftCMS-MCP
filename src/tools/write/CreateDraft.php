<?php

namespace eventiva\mcp\tools\write;

use Craft;
use craft\elements\Entry;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\Serializes;
use eventiva\mcp\tools\Reviews;
use eventiva\mcp\tools\Writes;

class CreateDraft extends AbstractTool
{
    use Serializes, Writes, Reviews;

    public function name(): string
    {
        return 'create_draft';
    }

    public function description(): string
    {
        return 'Creates a draft of an existing entry and optionally applies changes to it. The live entry stays untouched until publish_draft is called (or a human publishes it in the control panel). Preferred for risky edits of live content.';
    }

    public function scope(): string
    {
        return 'write';
    }

    public function properties(): array
    {
        return [
            'id' => ['type' => 'integer', 'description' => 'ID of the live entry.'],
            'name' => ['type' => 'string', 'description' => 'Draft name.'],
            'title' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'fields' => $this->fieldsProperty(),
            'allowBlockRemoval' => $this->allowBlockRemovalProperty(),
        ];
    }

    public function required(): array
    {
        return ['id'];
    }

    public function run(array $args): mixed
    {
        /** @var Entry|null $entry */
        $entry = Entry::find()->id((int)$args['id'])->status(null)->one();
        if (!$entry) {
            throw new \InvalidArgumentException("Entry {$args['id']} not found.");
        }
        if ($section = $entry->getSection()) {
            $this->assertSectionAllowed($section->handle);
        }
        $this->assertCanSave($entry);

        $existingChange = null;
        if ($this->reviewRequired()) {
            [$draft, $existingChange] = $this->resolveReviewDraft($entry);
        } else {
            /** @var Entry $draft */
            $draft = Craft::$app->getDrafts()->createDraft($entry, $this->user()->id, $args['name'] ?? 'MCP draft', 'Created via MCP');
        }

        $changed = false;
        if (array_key_exists('title', $args)) {
            $draft->title = (string)$args['title'];
            $changed = true;
        }
        if (array_key_exists('slug', $args)) {
            $draft->slug = (string)$args['slug'];
            $changed = true;
        }
        if (!empty($args['fields'])) {
            $this->applyFields($draft, $args['fields'], $this->boolArg($args['allowBlockRemoval'] ?? null));
            $changed = true;
        }
        if ($changed) {
            $this->saveOrFail($draft);
        }

        $result = $this->entrySummary($draft) + ['draftElementId' => $draft->id] + $this->writeExtras($draft);
        if ($this->reviewRequired()) {
            $change = $this->registerReviewChange($draft, $existingChange, $entry);
            $result += $this->pendingInfo($change, 'The draft is NOT live. A human must approve it in the control panel (MCP → Freigaben). Use list_my_changes to see the decision.');
        }
        return $result;
    }
}
