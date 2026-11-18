<?php

namespace eventiva\mcp\tools\read;

use Craft;
use eventiva\mcp\tools\AbstractTool;

class ListTaxonomies extends AbstractTool
{
    public function name(): string
    {
        return 'list_taxonomies';
    }

    public function description(): string
    {
        return 'Lists category groups and tag groups (handles) for use with search_terms / create_term.';
    }

    public function run(array $args): mixed
    {
        return [
            'categoryGroups' => array_map(
                fn($g) => ['handle' => $g->handle, 'name' => $g->name, 'maxLevels' => $g->maxLevels],
                Craft::$app->getCategories()->getAllGroups()
            ),
            'tagGroups' => array_map(
                fn($g) => ['handle' => $g->handle, 'name' => $g->name],
                Craft::$app->getTags()->getAllTagGroups()
            ),
        ];
    }
}
