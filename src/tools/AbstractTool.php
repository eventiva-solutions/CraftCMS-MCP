<?php

namespace eventiva\mcp\tools;

use Craft;
use craft\elements\User;
use eventiva\mcp\Mcp;

abstract class AbstractTool
{
    /** Unique snake_case tool name. */
    abstract public function name(): string;

    abstract public function description(): string;

    /** Required token scope: read | write | delete */
    public function scope(): string
    {
        return 'read';
    }

    /** JSON schema "properties" map. */
    public function properties(): array
    {
        return [];
    }

    /** @return string[] */
    public function required(): array
    {
        return [];
    }

    /**
     * Executes the tool. Throw \InvalidArgumentException / \RuntimeException
     * with a helpful message on errors – it is returned to the model.
     */
    abstract public function run(array $args): mixed;

    public function inputSchema(): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $this->properties() ?: new \stdClass(),
        ];
        if ($this->required()) {
            $schema['required'] = $this->required();
        }
        return $schema;
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'inputSchema' => $this->inputSchema(),
        ];
    }

    // -- helpers ---------------------------------------------------------

    protected function user(): User
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user instanceof User) {
            throw new \RuntimeException('No user bound to token.');
        }
        return $user;
    }

    /**
     * Some MCP clients send arrays/objects as JSON strings. Decode those; leave everything else alone.
     */
    protected function jsonArg(mixed $value): mixed
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && in_array($trimmed[0], ['[', '{'], true)) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        return $value;
    }

    /** A list of strings from an array, a JSON string or a comma separated string; null if empty. */
    protected function stringList(mixed $value): ?array
    {
        $value = $this->jsonArg($value);
        if (is_string($value) && $value !== '') {
            $value = array_map('trim', explode(',', $value));
        }
        if (!is_array($value)) {
            return null;
        }
        $list = array_values(array_filter(array_map('strval', $value), fn(string $s) => $s !== ''));
        return $list ?: null;
    }

    /** Booleans arrive as real booleans, but also as "true"/"false"/"1"/"0" strings. "false" must be false. */
    protected function boolArg(mixed $value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool)$value;
    }

    protected function limit(array $args, int $default = 20, int $max = 100): int
    {
        return max(1, min($max, (int)($args['limit'] ?? $default)));
    }

    protected function assertSectionAllowed(string $handle): void
    {
        if (!Mcp::getInstance()->tokens->isSectionAllowed($handle)) {
            throw new \RuntimeException("This token has no access to section '$handle'.");
        }
    }

    /** Section handles a query may use, honouring the token restriction. */
    protected function sectionFilter(?string $requested): ?array
    {
        $allowed = Mcp::getInstance()->tokens->current?->getAllowedSectionList();
        if ($requested !== null && $requested !== '') {
            $this->assertSectionAllowed($requested);
            return [$requested];
        }
        return $allowed;
    }
}
