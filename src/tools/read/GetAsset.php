<?php

namespace eventiva\mcp\tools\read;

use Craft;
use craft\elements\Asset;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\Serializes;

class GetAsset extends AbstractTool
{
    use Serializes;

    public function name(): string
    {
        return 'get_asset';
    }

    public function description(): string
    {
        return 'Returns one asset with metadata and custom field values.';
    }

    public function properties(): array
    {
        return ['id' => ['type' => 'integer', 'description' => 'Asset ID.']];
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
        if (!Craft::$app->getElements()->canView($asset, $this->user())) {
            throw new \RuntimeException('The token user may not view this asset.');
        }
        return $this->assetSummary($asset) + ['fields' => $asset->getSerializedFieldValues()];
    }
}
