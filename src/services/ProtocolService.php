<?php

namespace eventiva\mcp\services;

use Craft;
use craft\base\Component;
use eventiva\mcp\Mcp;
use eventiva\mcp\records\TokenRecord;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\delete;
use eventiva\mcp\tools\read;
use eventiva\mcp\tools\write;

/**
 * Minimal MCP (JSON-RPC 2.0) implementation for the Streamable HTTP transport.
 */
class ProtocolService extends Component
{
    public const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private ?array $tools = null;

    /** @return array<string, AbstractTool> */
    public function tools(): array
    {
        if ($this->tools === null) {
            $classes = [
                read\ListSites::class,
                read\ListSections::class,
                read\GetEntryType::class,
                read\SearchEntries::class,
                read\GetEntry::class,
                read\ListVolumes::class,
                read\SearchAssets::class,
                read\GetAsset::class,
                read\ListAssetFolders::class,
                read\ListTaxonomies::class,
                read\SearchTerms::class,
                read\ListMyChanges::class,
                write\CreateEntry::class,
                write\UpdateEntry::class,
                write\CreateDraft::class,
                write\PublishDraft::class,
                write\UploadAsset::class,
                write\UpdateAsset::class,
                write\CreateAssetFolder::class,
                write\CreateTerm::class,
                write\UpdateTerm::class,
                delete\DeleteEntry::class,
                delete\DeleteAsset::class,
                delete\DeleteTerm::class,
            ];
            $this->tools = [];
            foreach ($classes as $class) {
                /** @var AbstractTool $tool */
                $tool = new $class();
                $this->tools[$tool->name()] = $tool;
            }
        }
        return $this->tools;
    }

    /**
     * Handles one JSON-RPC message. Returns null for notifications.
     */
    public function handle(mixed $message, TokenRecord $token): ?array
    {
        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || !isset($message['method'])) {
            return $this->error($message['id'] ?? null, -32600, 'Invalid Request');
        }

        $id = $message['id'] ?? null;
        $isNotification = !array_key_exists('id', $message);
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        try {
            $result = match ($message['method']) {
                'initialize' => $this->initialize($params, $token),
                'ping' => new \stdClass(),
                'tools/list' => $this->listTools($token),
                'tools/call' => $this->callTool($params, $token),
                default => str_starts_with($message['method'], 'notifications/')
                    ? null
                    : throw new MethodNotFound($message['method']),
            };
        } catch (MethodNotFound $e) {
            return $isNotification ? null : $this->error($id, -32601, 'Method not found: ' . $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $isNotification ? null : $this->error($id, -32602, $e->getMessage());
        } catch (\Throwable $e) {
            Craft::error($e, __METHOD__);
            return $isNotification ? null : $this->error($id, -32603, 'Internal error');
        }

        if ($isNotification || $result === null) {
            return null;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function initialize(array $params, TokenRecord $token): array
    {
        $requested = $params['protocolVersion'] ?? null;
        $version = in_array($requested, self::SUPPORTED_VERSIONS, true) ? $requested : self::SUPPORTED_VERSIONS[0];

        return [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => [
                'name' => 'craft-mcp',
                'title' => 'Craft CMS MCP',
                'version' => Mcp::getInstance()->getVersion(),
            ],
            'instructions' => 'Manage content of a Craft CMS site. Start with list_sections and get_entry_type to learn the structure and field handles, then search_entries / get_entry. Sections, fields and system settings are read-only or unavailable.'
                . ($token->requireReview
                    ? ' Your token requires human approval: create_entry, update_entry, delete_* and upload_asset never change live content directly. They save a draft or a request that a person reviews in the control panel. Check decisions and rejection reasons with list_my_changes and revise rejected work; if a reason is vague, ask the user instead of guessing.'
                    : ''),
        ];
    }

    private function listTools(TokenRecord $token): array
    {
        $out = [];
        foreach ($this->tools() as $tool) {
            if ($token->hasScope($tool->scope()) && !($token->requireReview && !$tool->availableInReview())) {
                $out[] = $tool->definition();
            }
        }
        return ['tools' => $out];
    }

    private function callTool(array $params, TokenRecord $token): array
    {
        $name = (string)($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $tool = $this->tools()[$name] ?? null;
        if (!$tool) {
            throw new \InvalidArgumentException("Unknown tool: $name");
        }

        $audit = Mcp::getInstance()->audit;
        $start = microtime(true);
        $ms = fn() => (int)round((microtime(true) - $start) * 1000);

        if (!$token->hasScope($tool->scope())) {
            $msg = "Token lacks the '{$tool->scope()}' scope required for $name.";
            $audit->log($token, $name, $args, false, $msg, $ms());
            return $this->toolResult($msg, true);
        }

        if ($token->requireReview && !$tool->availableInReview()) {
            $msg = "$name is not available for tokens that require approval: it cannot be reviewed before it takes effect. Use create_entry / update_entry (they create drafts for approval) instead.";
            $audit->log($token, $name, $args, false, $msg, $ms());
            return $this->toolResult($msg, true);
        }

        foreach ($tool->required() as $key) {
            if (!array_key_exists($key, $args) || $args[$key] === '' || $args[$key] === null) {
                $msg = "Missing required argument '$key'.";
                $audit->log($token, $name, $args, false, $msg, $ms());
                return $this->toolResult($msg, true);
            }
        }

        try {
            $data = $tool->run($args);
            $audit->log($token, $name, $args, true, 'ok', $ms());
            return $this->toolResult(
                json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT),
                false
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $audit->log($token, $name, $args, false, $e->getMessage(), $ms());
            return $this->toolResult($e->getMessage(), true);
        } catch (\Throwable $e) {
            Craft::error($e, __METHOD__);
            $audit->log($token, $name, $args, false, $e::class . ': ' . $e->getMessage(), $ms());
            return $this->toolResult('Tool failed: ' . $e->getMessage(), true);
        }
    }

    private function toolResult(string $text, bool $isError): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError];
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}

/** @internal */
class MethodNotFound extends \RuntimeException
{
}
