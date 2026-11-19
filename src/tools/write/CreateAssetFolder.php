<?php

namespace eventiva\mcp\tools\write;

use Craft;
use craft\models\VolumeFolder;
use eventiva\mcp\tools\AbstractTool;

class CreateAssetFolder extends AbstractTool
{
    public function name(): string
    {
        return 'create_asset_folder';
    }

    public function description(): string
    {
        return 'Creates a subfolder inside an existing asset folder.';
    }

    public function scope(): string
    {
        return 'write';
    }

    /** Not reviewable: there is no draft mechanism for this, so it is unavailable while approval is required. */
    public function availableInReview(): bool
    {
        return false;
    }

    public function properties(): array
    {
        return [
            'parentFolderId' => ['type' => 'integer', 'description' => 'See list_asset_folders.'],
            'name' => ['type' => 'string'],
        ];
    }

    public function required(): array
    {
        return ['parentFolderId', 'name'];
    }

    public function run(array $args): mixed
    {
        $assets = Craft::$app->getAssets();
        $parent = $assets->getFolderById((int)$args['parentFolderId']);
        if (!$parent) {
            throw new \InvalidArgumentException('Unknown parentFolderId. Use list_asset_folders.');
        }

        $volume = $parent->getVolume();
        $user = $this->user();
        if (!$user->can('createFolders:' . $volume->uid) && !$user->admin) {
            throw new \RuntimeException('The token user may not create folders in this volume.');
        }

        $name = trim(preg_replace('/[^\p{L}\p{N}_\- ]+/u', '', (string)$args['name']));
        if ($name === '') {
            throw new \InvalidArgumentException('Invalid folder name.');
        }

        $folder = new VolumeFolder();
        $folder->parentId = $parent->id;
        $folder->volumeId = $parent->volumeId;
        $folder->name = $name;
        $folder->path = ($parent->path ?? '') . $name . '/';
        $assets->createFolder($folder);

        return ['id' => $folder->id, 'name' => $folder->name, 'path' => $folder->path, 'parentId' => $folder->parentId];
    }
}
