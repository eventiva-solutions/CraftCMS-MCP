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
        return 'Updates an entry. Only the given arguments change; "fields" is merged partially (omitted fields stay untouched). Also used to enable/disable (enabled), move in a structure (parentId, 0 = root) or rename (title/slug). Use search_entries/get_entry first.';
    }

    public function scope(): string
    {
        return 'write';
    }

    public function properties(): array
    {
        return [
            'id' => ['type' => 'integer'],
            'site' => ['type' => 'string', 'description' => 'Site handle (default: primary site).'],
            'title' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'enabled' => ['type' => 'boolean'],
            'postDate' => ['type' => 'string', 'description' => 'ISO 8601 date.'],
            'parentId' => ['type' => 'integer', 'description' => 'New parent entry ID in a structure section; 0 moves to root.'],
            'fields' => $this->fieldsProperty(),
        ];
    }

    public function required(): array
    {
        return ['id'];
    }

    public function run(array $args): mixed
    {
        $q = Entry::find()->id((int)$args['id'])->status(null);
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
            $entry->enabled = (bool)$args['enabled'];
        }
        if (!empty($args['postDate'])) {
            $entry->postDate = $this->parseDate($args['postDate']);
        }
        if (array_key_exists('parentId', $args)) {
            $entry->setParentId((int)$args['parentId'] ?: null);
        }
        $this->applyFields($entry, $args['fields'] ?? null);

        $this->assertCanSave($entry);
        $this->saveOrFail($entry);

        return $this->entrySummary($entry);
    }
}
