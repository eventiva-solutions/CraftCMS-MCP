<?php

namespace eventiva\mcp\tools\read;

use Craft;
use eventiva\mcp\Mcp;
use eventiva\mcp\tools\AbstractTool;

class ListSections extends AbstractTool
{
    public function name(): string
    {
        return 'list_sections';
    }

    public function description(): string
    {
        return 'Lists all sections with type (single/channel/structure), entry types and sites. Read-only; sections cannot be modified via MCP. Call get_entry_type to see the fields of an entry type.';
    }

    public function run(array $args): mixed
    {
        $out = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (!Mcp::getInstance()->tokens->isSectionAllowed($section->handle)) {
                continue;
            }
            $sites = [];
            foreach ($section->getSiteSettings() as $settings) {
                $site = Craft::$app->getSites()->getSiteById($settings->siteId);
                $sites[] = [
                    'site' => $site?->handle,
                    'hasUrls' => $settings->hasUrls,
                    'uriFormat' => $settings->uriFormat,
                ];
            }
            $out[] = [
                'handle' => $section->handle,
                'name' => $section->name,
                'type' => $section->type,
                'maxLevels' => $section->type === 'structure' ? $section->maxLevels : null,
                'entryTypes' => array_map(fn($t) => $t->handle, $section->getEntryTypes()),
                'sites' => $sites,
            ];
        }
        return $out;
    }
}
