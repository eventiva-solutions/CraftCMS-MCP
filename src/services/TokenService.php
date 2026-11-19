<?php

namespace eventiva\mcp\services;

use craft\base\Component;
use craft\helpers\Db;
use eventiva\mcp\records\TokenRecord;

class TokenService extends Component
{
    public const SCOPES = ['read', 'write', 'delete'];

    /** Token of the request currently being processed. */
    public ?TokenRecord $current = null;

    /**
     * Creates a token. The plain token is returned ONCE and never stored.
     *
     * @param string[] $scopes
     * @param string[]|null $allowedSections
     * @return array{0: TokenRecord, 1: string}
     */
    public function create(string $name, int $userId, array $scopes, ?\DateTime $expiresAt = null, ?array $allowedSections = null, bool $requireReview = false): array
    {
        $scopes = array_values(array_intersect(self::SCOPES, array_unique(array_merge(['read'], $scopes))));
        $plain = 'cmcp_' . bin2hex(random_bytes(32));

        $record = new TokenRecord();
        $record->name = $name;
        $record->tokenHash = hash('sha256', $plain);
        $record->tokenPrefix = substr($plain, 0, 10);
        $record->userId = $userId;
        $record->scopes = implode(',', $scopes);
        $record->allowedSections = $allowedSections ? json_encode(array_values($allowedSections)) : null;
        $record->requireReview = $requireReview;
        $record->expiresAt = $expiresAt ? Db::prepareDateForDb($expiresAt) : null;
        $record->save(false);

        return [$record, $plain];
    }

    /**
     * Returns the token for a plain token string, or null if unknown/expired.
     */
    public function authenticate(string $plain): ?TokenRecord
    {
        if ($plain === '') {
            return null;
        }

        /** @var TokenRecord|null $record */
        $record = TokenRecord::findOne(['tokenHash' => hash('sha256', $plain)]);
        if (!$record) {
            return null;
        }

        if ($record->expiresAt !== null && Db::prepareDateForDb($record->expiresAt) < Db::prepareDateForDb(new \DateTime())) {
            return null;
        }

        // Update lastUsedAt at most once per minute to avoid a write per request.
        $last = $record->lastUsedAt ? strtotime($record->lastUsedAt . ' UTC') : 0;
        if (time() - $last > 60) {
            $record->lastUsedAt = Db::prepareDateForDb(new \DateTime());
            $record->save(false);
        }

        return $record;
    }

    /** @return TokenRecord[] */
    public function all(): array
    {
        return TokenRecord::find()->orderBy(['dateCreated' => SORT_DESC])->all();
    }

    public function revoke(int $id): bool
    {
        $record = TokenRecord::findOne($id);
        return $record ? (bool)$record->delete() : false;
    }

    public function isSectionAllowed(string $handle): bool
    {
        $allowed = $this->current?->getAllowedSectionList();
        return $allowed === null || in_array($handle, $allowed, true);
    }
}
