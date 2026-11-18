<?php

namespace eventiva\mcp\tools\read;

use Craft;
use eventiva\mcp\tools\AbstractTool;

class ListAssetFolders extends AbstractTool
{
    public function name(): string
    {
        return 'list_asset_folders';
    }

    public function description(): string
    {
        return 'Lists all folders of a volume (id, path). Use folder IDs for upload_asset, move_asset and search_assets.';
    }

    public function properties(): array
    {
        return ['volume' => ['type' => 'string', 'description' => 'Volume handle.']];
    }

    public function required(): array
    {
        return ['volume'];
    }

    public function run(array $args): mixed
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($args['volume']);
        if (!$volume) {
            throw new \InvalidArgumentException("Unknown volume '{$args['volume']}'. Use list_volumes.");
        }
        $assets = Craft::$app->getAssets();
        $root = $assets->getRootFolderByVolumeId($volume->id);

        $out = [['id' => $root->id, 'name' => $root->name, 'path' => '', 'parentId' => null]];
        foreach ($assets->getAllDescendantFolders($root) as $folder) {
            $out[] = ['id' => $folder->id, 'name' => $folder->name, 'path' => $folder->path, 'parentId' => $folder->parentId];
        }
        return $out;
    }
}
