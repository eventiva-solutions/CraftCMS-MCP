<?php

namespace eventiva\mcp\tools\read;

use Craft;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\TermHelper;

class SearchTerms extends AbstractTool
{
    use TermHelper;

    public function name(): string
    {
        return 'search_terms';
    }

    public function description(): string
    {
        return 'Finds categories or tags by search text and/or group. Use the returned IDs in relation fields of entries.';
    }

    public function properties(): array
    {
        return [
            'type' => $this->typeProperty(),
            'group' => ['type' => 'string', 'description' => 'Group handle (see list_taxonomies).'],
            'query' => ['type' => 'string'],
            'limit' => ['type' => 'integer', 'description' => 'Max 100, default 20.'],
        ];
    }

    public function required(): array
    {
        return ['type'];
    }

    public function run(array $args): mixed
    {
        $class = $this->termClass($args['type']);
        $q = $class::find()->status(null)->limit($this->limit($args));
        if (!empty($args['group'])) {
            $q->group($args['group']);
        }
        if (!empty($args['query'])) {
            $q->search((string)$args['query']);
        }

        $user = $this->user();
        $elements = Craft::$app->getElements();
        $out = [];
        foreach ($q->all() as $term) {
            if ($elements->canView($term, $user)) {
                $out[] = $this->termSummary($term);
            }
        }
        return $out;
    }
}
