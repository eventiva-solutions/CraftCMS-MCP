<?php

namespace eventiva\mcp\tools\read;

use Craft;
use eventiva\mcp\tools\AbstractTool;

class ListSites extends AbstractTool
{
    public function name(): string
    {
        return 'list_sites';
    }

    public function description(): string
    {
        return 'Lists all sites (handle, language, base URL). Use site handles in other tools.';
    }

    public function run(array $args): mixed
    {
        return array_map(fn($s) => [
            'id' => $s->id,
            'handle' => $s->handle,
            'name' => $s->getName(),
            'language' => $s->language,
            'primary' => $s->primary,
            'baseUrl' => $s->getBaseUrl(),
        ], Craft::$app->getSites()->getAllSites());
    }
}
