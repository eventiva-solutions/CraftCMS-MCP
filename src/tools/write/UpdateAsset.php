<?php

namespace eventiva\mcp\tools\write;

use Craft;
use craft\elements\Asset;
use craft\helpers\FileHelper;
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

    /** Not reviewable: there is no draft mechanism for this, so it is unavailable while approval is required. */
    public function availableInReview(): bool
    {
        return false;
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
            $asset->alt = $args['alt'] === '' || $args['alt'] === null ? null : (string)$args['alt'];
        }
        $this->applyFields($asset, $args['fields'] ?? null);

        $this->assertCanSave($asset);
        $this->saveOrFail($asset);

        // Renaming / moving goes through the Assets service (handles the file operation).
        if (!empty($args['filename']) || !empty($args['folderId'])) {
            $assets = Craft::$app->getAssets();
            $folder = !empty($args['folderId']) ? $assets->getFolderById((int)$args['folderId']) : $asset->getFolder();
            if (!$folder) {
                throw new \InvalidArgumentException('Unknown folderId. Use list_asset_folders.');
            }
            $filename = !empty($args['filename']) ? FileHelper::sanitizeFilename((string)$args['filename']) : '';
            if ($filename !== '' && !str_contains($filename, '.')) {
                throw new \InvalidArgumentException('filename must include the extension.');
            }
            if (!Craft::$app->getElements()->canSave($asset, $this->user())) {
                throw new \RuntimeException('The token user is not allowed to move or rename this asset.');
            }
            if (!$assets->moveAsset($asset, $folder, $filename)) {
                throw new \RuntimeException('Move/rename failed: ' . implode(' | ', $asset->getFirstErrors()));
            }
        }

        return $this->assetSummary($asset);
    }
}
