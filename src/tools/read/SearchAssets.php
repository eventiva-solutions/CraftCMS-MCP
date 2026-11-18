<?php

namespace eventiva\mcp\tools\read;

use Craft;
use craft\elements\Asset;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\Serializes;

class SearchAssets extends AbstractTool
{
    use Serializes;

    public function name(): string
    {
        return 'search_assets';
    }

    public function description(): string
    {
        return 'Finds assets (images, documents, videos) by search text, volume, kind or folder.';
    }

    public function properties(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Search text (filename, title, alt …).'],
            'volume' => ['type' => 'string', 'description' => 'Volume handle.'],
            'folderId' => ['type' => 'integer'],
            'kind' => ['type' => 'string', 'description' => 'image, pdf, video, audio, text, word, excel, powerpoint, json, compressed, unknown …'],
            'limit' => ['type' => 'integer', 'description' => 'Max 100, default 20.'],
            'offset' => ['type' => 'integer'],
        ];
    }

    public function run(array $args): mixed
    {
        $q = Asset::find()
            ->limit($this->limit($args))
            ->offset(max(0, (int)($args['offset'] ?? 0)))
            ->orderBy('dateCreated DESC');

        if (!empty($args['query'])) {
            $q->search((string)$args['query']);
        }
        if (!empty($args['volume'])) {
            $q->volume($args['volume']);
        }
        if (!empty($args['folderId'])) {
            $q->folderId((int)$args['folderId']);
        }
        if (!empty($args['kind'])) {
            $q->kind($args['kind']);
        }

        $user = $this->user();
        $elements = Craft::$app->getElements();
        $results = [];
        foreach ($q->all() as $asset) {
            if ($elements->canView($asset, $user)) {
                $results[] = $this->assetSummary($asset);
            }
        }

        return ['total' => (int)$q->limit(null)->offset(null)->count(), 'results' => $results];
    }
}
