<?php

namespace eventiva\mcp\tools\write;

use Craft;
use craft\elements\Entry;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\Serializes;
use eventiva\mcp\tools\Reviews;
use eventiva\mcp\tools\Writes;

class CreateEntry extends AbstractTool
{
    use Serializes, Writes, Reviews;

    public function name(): string
    {
        return 'create_entry';
    }

    public function description(): string
    {
        return 'Creates a new entry. New entries are disabled by default (enabled=false) so a human can review them; set enabled=true to publish immediately. Call get_entry_type first to learn valid field handles.';
    }

    public function scope(): string
    {
        return 'write';
    }

    public function properties(): array
    {
        return [
            'section' => ['type' => 'string', 'description' => 'Section handle.'],
            'entryType' => ['type' => 'string', 'description' => 'Entry type handle (optional if the section has only one).'],
            'title' => ['type' => 'string'],
            'slug' => ['type' => 'string', 'description' => 'Optional, generated from title otherwise.'],
            'site' => ['type' => 'string', 'description' => 'Site handle (default: primary site).'],
            'enabled' => ['type' => 'boolean', 'description' => 'Default false.'],
            'postDate' => ['type' => 'string', 'description' => 'ISO 8601 date.'],
            'parentId' => ['type' => 'integer', 'description' => 'Parent entry ID (structure sections).'],
            'fields' => $this->fieldsProperty(),
        ];
    }

    public function required(): array
    {
        return ['section', 'title'];
    }

    public function run(array $args): mixed
    {
        $entries = Craft::$app->getEntries();
        $section = $entries->getSectionByHandle($args['section']);
        if (!$section) {
            throw new \InvalidArgumentException("Unknown section '{$args['section']}'. Use list_sections.");
        }
        $this->assertSectionAllowed($section->handle);

        $types = $section->getEntryTypes();
        if (!empty($args['entryType'])) {
            $type = null;
            foreach ($types as $t) {
                if ($t->handle === $args['entryType']) {
                    $type = $t;
                }
            }
            if (!$type) {
                throw new \InvalidArgumentException("Entry type '{$args['entryType']}' is not part of section '{$section->handle}'. Available: " . implode(', ', array_map(fn($t) => $t->handle, $types)));
            }
        } elseif (count($types) === 1) {
            $type = $types[0];
        } else {
            throw new \InvalidArgumentException('Please specify entryType. Available: ' . implode(', ', array_map(fn($t) => $t->handle, $types)));
        }

        $site = !empty($args['site']) ? Craft::$app->getSites()->getSiteByHandle($args['site']) : Craft::$app->getSites()->getPrimarySite();
        if (!$site) {
            throw new \InvalidArgumentException("Unknown site '{$args['site']}'.");
        }

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $type->id;
        $entry->siteId = $site->id;
        $entry->title = $args['title'];
        $entry->enabled = $this->boolArg($args['enabled'] ?? null, false);
        $entry->setAuthorId($this->user()->id);
        if (!empty($args['slug'])) {
            $entry->slug = $args['slug'];
        }
        if (!empty($args['postDate'])) {
            $entry->postDate = $this->parseDate($args['postDate']);
        }
        if (!empty($args['parentId'])) {
            $entry->setParentId((int)$args['parentId']);
        }
        $this->applyFields($entry, $args['fields'] ?? null);

        $this->assertCanSave($entry);

        if ($this->reviewRequired()) {
            // Saved as an unpublished draft; it becomes a real entry only after a human approves it.
            if (!Craft::$app->getDrafts()->saveElementAsDraft($entry, $this->user()->id, 'MCP: ' . mb_strimwidth((string)$entry->title, 0, 60, '…'), 'Created via MCP, waiting for approval')) {
                $errors = [];
                foreach ($entry->getErrors() as $attr => $messages) {
                    $errors[] = $attr . ': ' . implode(' ', $messages);
                }
                throw new \RuntimeException('Saving failed – ' . ($errors ? implode(' | ', $errors) : 'unknown error'));
            }
            $change = $this->registerReviewChange($entry, null, null);
            return $this->entrySummary($entry) + $this->writeExtras($entry) + $this->pendingInfo(
                $change,
                'The entry was saved as a draft and is NOT live. A human must approve it in the control panel (MCP → Freigaben). Use list_my_changes to see the decision.',
                ['draftElementId' => $entry->id]
            );
        }

        $this->saveOrFail($entry);

        return $this->entrySummary($entry) + $this->writeExtras($entry);
    }
}
