<?php

namespace eventiva\mcp\tools\write;

use craft\elements\Entry;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\Serializes;
use eventiva\mcp\tools\Writes;

class UpdateEntry extends AbstractTool
{
    use Serializes, Writes;

    public function name(): string
    {
        return 'update_entry';
    }

    public function description(): string
    {
        return 'Updates an entry or a draft (pass the draftElementId from create_draft). Only the given arguments change; fields you do not send stay untouched, BUT a Matrix field you send replaces all its blocks (see the "fields" description; blocks that would be deleted are refused unless allowBlockRemoval=true). Also used to enable/disable (enabled), move in a structure (parentId, 0 = root) or rename (title/slug). Read the entry with get_entry first.';
    }

    public function scope(): string
    {
        return 'write';
    }

    public function properties(): array
    {
        return [
            'id' => ['type' => 'integer', 'description' => 'Entry ID or draftElementId.'],
            'site' => ['type' => 'string', 'description' => 'Site handle (default: primary site).'],
            'title' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'enabled' => ['type' => 'boolean'],
            'postDate' => ['type' => 'string', 'description' => 'ISO 8601 date.'],
            'parentId' => ['type' => 'integer', 'description' => 'New parent entry ID in a structure section; 0 moves to root.'],
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
        $q = Entry::find()->id((int)$args['id'])->drafts(null)->status(null);
        if (!empty($args['site'])) {
            $q->site($args['site']);
        }
        /** @var Entry|null $entry */
        $entry = $q->one();
        if (!$entry) {
            throw new \InvalidArgumentException("Entry {$args['id']} not found.");
        }
        if ($section = $entry->getSection()) {
            $this->assertSectionAllowed($section->handle);
        }

        if (array_key_exists('title', $args)) {
            $entry->title = (string)$args['title'];
        }
        if (array_key_exists('slug', $args)) {
            $entry->slug = (string)$args['slug'];
        }
        if (array_key_exists('enabled', $args)) {
            $entry->enabled = $this->boolArg($args['enabled']);
        }
        if (!empty($args['postDate'])) {
            $entry->postDate = $this->parseDate($args['postDate']);
        }
        if (array_key_exists('parentId', $args)) {
            $entry->setParentId((int)$args['parentId'] ?: null);
        }
        $this->applyFields($entry, $args['fields'] ?? null, $this->boolArg($args['allowBlockRemoval'] ?? null));

        $this->assertCanSave($entry);
        $this->saveOrFail($entry);

        return $this->entrySummary($entry) + $this->writeExtras($entry);
    }
}
