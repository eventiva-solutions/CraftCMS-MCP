<?php

namespace eventiva\mcp\tools\delete;

use Craft;
use craft\elements\Asset;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\Reviews;
use eventiva\mcp\tools\Writes;

class DeleteAsset extends AbstractTool
{
    use Writes, Reviews;

    public function name(): string
    {
        return 'delete_asset';
    }

    public function description(): string
    {
        return 'Deletes an asset (soft delete in Craft; the file is removed from the volume when trashed assets are purged). Entries referencing it lose the relation.';
    }

    public function scope(): string
    {
        return 'delete';
    }

    public function properties(): array
    {
        return ['id' => ['type' => 'integer']];
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
        $this->assertCanDelete($asset);

        if ($this->reviewRequired()) {
            $change = $this->review()->findPending(['asset_delete'], $asset->id)
                ?: $this->review()->queue('asset_delete', $asset->id, null, (string)$asset->filename, null, ['filename' => $asset->filename]);
            return $this->pendingInfo($change, 'Deletion was requested but NOT carried out. A human must approve it in the control panel (MCP → Freigaben). Use list_my_changes to see the decision.', ['deleted' => false, 'id' => (int)$args['id'], 'filename' => $asset->filename]);
        }

        if (!Craft::$app->getElements()->deleteElement($asset)) {
            throw new \RuntimeException('Asset could not be deleted.');
        }
        return ['deleted' => true, 'id' => (int)$args['id'], 'filename' => $asset->filename];
    }
}
