<?php

namespace eventiva\mcp\tools\read;

use Craft;
use eventiva\mcp\tools\AbstractTool;

class ListVolumes extends AbstractTool
{
    public function name(): string
    {
        return 'list_volumes';
    }

    public function description(): string
    {
        return 'Lists asset volumes with their root folder ID. Use the handle in search_assets and (later) upload_asset.';
    }

    public function run(array $args): mixed
    {
        $assets = Craft::$app->getAssets();
        return array_map(fn($v) => [
            'handle' => $v->handle,
            'name' => $v->name,
            'rootFolderId' => $assets->getRootFolderByVolumeId($v->id)->id,
        ], Craft::$app->getVolumes()->getAllVolumes());
    }
}
