<?php

namespace eventiva\mcp\tools\write;

use Craft;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\Serializes;
use eventiva\mcp\tools\Writes;

class UploadAsset extends AbstractTool
{
    use Serializes, Writes;

    private const MAX_BYTES = 20 * 1024 * 1024;

    public function name(): string
    {
        return 'upload_asset';
    }

    public function description(): string
    {
        return 'Uploads a file as asset, either from a public https URL ("url") or as base64 ("data"). Max 20 MB. Returns the asset incl. ID that can be set on asset fields of entries. Filename conflicts are resolved automatically.';
    }

    public function scope(): string
    {
        return 'write';
    }

    public function properties(): array
    {
        return [
            'volume' => ['type' => 'string', 'description' => 'Volume handle (see list_volumes).'],
            'folderId' => ['type' => 'integer', 'description' => 'Target folder ID (default: volume root; see list_asset_folders).'],
            'filename' => ['type' => 'string', 'description' => 'Target filename with extension. Optional for url uploads.'],
            'url' => ['type' => 'string', 'description' => 'Public http(s) URL to download.'],
            'data' => ['type' => 'string', 'description' => 'Base64 encoded file content.'],
            'title' => ['type' => 'string'],
            'alt' => ['type' => 'string'],
            'fields' => $this->fieldsProperty(),
        ];
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
        $folderId = !empty($args['folderId']) ? (int)$args['folderId'] : $assets->getRootFolderByVolumeId($volume->id)->id;
        $folder = $assets->getFolderById($folderId);
        if (!$folder || (int)$folder->volumeId !== (int)$volume->id) {
            throw new \InvalidArgumentException('folderId does not belong to the given volume. Use list_asset_folders.');
        }

        if (!empty($args['url'])) {
            $content = $this->download((string)$args['url']);
            $filename = $args['filename'] ?? basename(parse_url($args['url'], PHP_URL_PATH) ?: '');
        } elseif (!empty($args['data'])) {
            $content = base64_decode((string)$args['data'], true);
            if ($content === false) {
                throw new \InvalidArgumentException('"data" is not valid base64.');
            }
            $filename = $args['filename'] ?? '';
        } else {
            throw new \InvalidArgumentException('Provide either "url" or "data".');
        }

        if (strlen($content) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('File exceeds the 20 MB limit.');
        }
        $filename = FileHelper::sanitizeFilename((string)$filename);
        if ($filename === '' || !str_contains($filename, '.')) {
            throw new \InvalidArgumentException('A filename with extension is required.');
        }

        $tmp = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'mcp_' . bin2hex(random_bytes(6)) . '_' . $filename;
        file_put_contents($tmp, $content);

        try {
            $asset = new Asset();
            $asset->tempFilePath = $tmp;
            $asset->filename = $filename;
            $asset->newFolderId = $folder->id;
            $asset->setVolumeId($volume->id);
            $asset->uploaderId = $this->user()->id;
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);
            if (!empty($args['title'])) {
                $asset->title = $args['title'];
            }
            if (!empty($args['alt'])) {
                $asset->alt = $args['alt'];
            }
            $this->applyFields($asset, $args['fields'] ?? null);
            $this->assertCanSave($asset);
            $this->saveOrFail($asset);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        return $this->assetSummary($asset);
    }

    private function download(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new \InvalidArgumentException('Only http(s) URLs are allowed.');
        }

        // SSRF protection: only public IPs.
        $ips = gethostbynamel($parts['host']) ?: [];
        if (!$ips) {
            throw new \InvalidArgumentException('Host could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \InvalidArgumentException('URLs pointing to private or reserved networks are not allowed.');
            }
        }

        $client = Craft::createGuzzleClient([
            'timeout' => 30,
            'allow_redirects' => false,
            'stream' => true,
            'headers' => ['User-Agent' => 'Craft-MCP/1.0'],
            'curl' => [CURLOPT_RESOLVE => [$parts['host'] . ':' . ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)) . ':' . $ips[0]]],
        ]);
        $response = $client->get($url);
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException('Download failed with HTTP ' . $response->getStatusCode() . ' (redirects are not followed).');
        }

        $body = $response->getBody();
        $data = '';
        while (!$body->eof()) {
            $data .= $body->read(65536);
            if (strlen($data) > self::MAX_BYTES) {
                throw new \InvalidArgumentException('File exceeds the 20 MB limit.');
            }
        }
        return $data;
    }
}
