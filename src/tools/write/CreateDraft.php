<?php

namespace eventiva\mcp\tools\write;

use Craft;
use craft\elements\Entry;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\Serializes;
use eventiva\mcp\tools\Writes;

class CreateDraft extends AbstractTool
{
    use Serializes, Writes;

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

        /** @var Entry $draft */
        $draft = Craft::$app->getDrafts()->createDraft($entry, $this->user()->id, $args['name'] ?? 'MCP draft', 'Created via MCP');

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
            $this->applyFields($draft, $args['fields']);
            $changed = true;
        }
        if ($changed) {
            $this->saveOrFail($draft);
        }

        return $this->entrySummary($draft) + ['draftId' => $draft->draftId, 'draftElementId' => $draft->id];
    }
}
