<?php

namespace eventiva\mcp\tools\write;

use Craft;
use craft\elements\Asset;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\Serializes;
use eventiva\mcp\tools\Writes;

class UpdateAsset extends AbstractTool
{
    use Serializes, Writes;

    public function name(): string
    {
        return 'update_asset';
    }

    public function description(): string
    {
        return 'Updates asset metadata (title, alt text, custom fields), renames it (filename) or moves it to another folder (folderId, same volume or another).';
    }

    public function scope(): string
    {
        return 'write';
    }

    public function properties(): array
    {
        return [
            'id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'alt' => ['type' => 'string'],
            'filename' => ['type' => 'string', 'description' => 'New filename incl. extension.'],
            'folderId' => ['type' => 'integer', 'description' => 'Move to this folder.'],
            'fields' => $this->fieldsProperty(),
        ];
    }

    public function required(): array
    {
        return ['id'];
    }

    public function run(array $args): mixed
    {
        /** @var Asset|null $asset */
        $asset = Asset::find()->id((int)$args['id'])->one();
        if (!$asset) {
            throw new \InvalidArgumentException("Asset {$args['id']} not found.");
        }

        if (array_key_exists('title', $args)) {
            $asset->title = (string)$args['title'];
        }
        if (array_key_exists('alt', $args)) {
            $asset->alt = (string)$args['alt'];
        }
        if (!empty($args['filename'])) {
            $asset->newFilename = (string)$args['filename'];
        }
        if (!empty($args['folderId'])) {
            if (!Craft::$app->getAssets()->getFolderById((int)$args['folderId'])) {
                throw new \InvalidArgumentException('Unknown folderId. Use list_asset_folders.');
            }
            $asset->newFolderId = (int)$args['folderId'];
        }
        $asset->setScenario(Asset::SCENARIO_FILEOPS);
        $this->applyFields($asset, $args['fields'] ?? null);

        $this->assertCanSave($asset);
        $this->saveOrFail($asset);

        return $this->assetSummary($asset);
    }
}
