<?php

namespace eventiva\mcp\services;

use craft\base\Component;
use eventiva\mcp\records\AuditRecord;
use eventiva\mcp\records\TokenRecord;

class AuditService extends Component
{
    private const MAX_ARG_LENGTH = 2000;
    private const RETENTION_DAYS = 90;

    public function log(?TokenRecord $token, string $tool, array $arguments, bool $success, string $message, int $durationMs): void
    {
        try {
            $json = json_encode($this->redact($arguments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (mb_strlen((string)$json) > self::MAX_ARG_LENGTH) {
                $json = mb_substr($json, 0, self::MAX_ARG_LENGTH) . '…';
            }

            $record = new AuditRecord();
            $record->tokenId = $token?->id;
            $record->tokenName = $token?->name;
            $record->tool = $tool;
            $record->arguments = $json;
            $record->success = $success;
            $record->message = mb_substr($message, 0, 1000);
            $record->durationMs = $durationMs;
            $record->save(false);

            // Occasional cleanup
            if (random_int(1, 200) === 1) {
                $this->prune();
            }
        } catch (\Throwable $e) {
            \Craft::warning('MCP audit log failed: ' . $e->getMessage(), __METHOD__);
        }
    }

    /** @return AuditRecord[] */
    public function recent(int $limit = 200): array
    {
        return AuditRecord::find()->orderBy(['id' => SORT_DESC])->limit($limit)->all();
    }

    public function prune(): void
    {
        $cutoff = (new \DateTime('-' . self::RETENTION_DAYS . ' days'))->format('Y-m-d H:i:s');
        AuditRecord::deleteAll(['<', 'dateCreated', $cutoff]);
    }

    /** Shortens large payloads (e.g. base64 uploads) before logging. */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (is_string($value) && strlen($value) > 300) {
                $data[$key] = substr($value, 0, 200) . '…[' . strlen($value) . ' chars]';
            }
        }
        return $data;
    }
}
