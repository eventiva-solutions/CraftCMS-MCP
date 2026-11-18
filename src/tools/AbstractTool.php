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
