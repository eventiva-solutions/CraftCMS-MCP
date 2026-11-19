<?php

namespace eventiva\mcp\tools\read;

use Craft;
use craft\elements\Entry;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\Serializes;

class SearchEntries extends AbstractTool
{
    use Serializes;

    public function name(): string
    {
        return 'search_entries';
    }

    public function description(): string
    {
        return 'Finds entries. "query" is a full-text search over titles and searchable fields (supports Craft search syntax, e.g. title:foo). Filter by section, entry type, site and status. Returns summaries; use get_entry for field values.';
    }

    public function properties(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Search text.'],
            'section' => ['type' => 'string', 'description' => 'Section handle.'],
            'entryType' => ['type' => 'string', 'description' => 'Entry type handle.'],
            'site' => ['type' => 'string', 'description' => 'Site handle (default: primary site).'],
            'status' => ['type' => 'string', 'enum' => ['live', 'pending', 'expired', 'disabled', 'any'], 'description' => 'Default: any.'],
            'orderBy' => ['type' => 'string', 'description' => 'e.g. "dateUpdated DESC" (default), "postDate DESC", "title ASC".'],
            'limit' => ['type' => 'integer', 'description' => 'Max 100, default 20.'],
            'offset' => ['type' => 'integer'],
        ];
    }

    public function run(array $args): mixed
    {
        $q = Entry::find()
            ->status(($args['status'] ?? 'any') === 'any' ? null : $args['status'])
            ->limit($this->limit($args))
            ->offset(max(0, (int)($args['offset'] ?? 0)))
            ->sectionId(':notempty:') // skip nested (Matrix) entries
            ->orderBy($this->orderBy($args['orderBy'] ?? 'dateUpdated DESC'));

        if (!empty($args['query'])) {
            $q->search((string)$args['query']);
            $q->orderBy(null)->orderBy('score');
        }
        if ($sections = $this->sectionFilter($args['section'] ?? null)) {
            $q->section($sections);
        }
        if (!empty($args['entryType'])) {
            $q->type($args['entryType']);
        }
        if (!empty($args['site'])) {
            $q->site($args['site']);
        }

        $user = $this->user();
        $elements = Craft::$app->getElements();
        $results = [];
        foreach ($q->all() as $entry) {
            if ($elements->canView($entry, $user)) {
                $results[] = $this->entrySummary($entry);
            }
        }

        return ['total' => (int)$q->limit(null)->offset(null)->count(), 'results' => $results];
    }

    private function orderBy(string $value): string
    {
        if (!preg_match('/^(title|slug|postDate|dateCreated|dateUpdated)(\s+(ASC|DESC))?$/i', trim($value))) {
            throw new \InvalidArgumentException('orderBy must be one of title, slug, postDate, dateCreated, dateUpdated, optionally followed by ASC or DESC.');
        }
        return trim($value);
    }
}
