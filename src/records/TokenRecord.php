<?php

namespace eventiva\mcp\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $tokenHash
 * @property string $tokenPrefix
 * @property int $userId
 * @property string $scopes Comma separated: read,write,delete
 * @property string|null $allowedSections JSON array of section handles (null = all)
 * @property string|null $expiresAt
 * @property string|null $lastUsedAt
 */
class TokenRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%mcp_tokens}}';
    }

    /** @return string[] */
    public function getScopeList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->scopes))));
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->getScopeList(), true);
    }

    /** @return string[]|null null = no restriction */
    public function getAllowedSectionList(): ?array
    {
        if ($this->allowedSections === null || $this->allowedSections === '') {
            return null;
        }
        $list = json_decode($this->allowedSections, true);
        return is_array($list) && $list ? array_values($list) : null;
    }
}
