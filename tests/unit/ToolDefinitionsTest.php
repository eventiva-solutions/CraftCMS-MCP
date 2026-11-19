<?php

namespace eventiva\mcp\tests\unit;

use eventiva\mcp\services\ProtocolService;
use eventiva\mcp\tools\AbstractTool;
use PHPUnit\Framework\TestCase;

/**
 * Guards the tool catalogue: naming, scopes, schemas and the "no admin surface" rule.
 */
class ToolDefinitionsTest extends TestCase
{
    /** @var array<string, AbstractTool> */
    private array $tools;

    protected function setUp(): void
    {
        $this->tools = (new ProtocolService())->tools();
    }

    public function testCatalogueIsNotEmptyAndNamesAreUnique(): void
    {
        $this->assertNotEmpty($this->tools);
        $names = array_map(fn(AbstractTool $t) => $t->name(), array_values($this->tools));
        $this->assertSame(count($names), count(array_unique($names)));
    }

    public function testNamesAreSnakeCase(): void
    {
        foreach ($this->tools as $name => $tool) {
            $this->assertMatchesRegularExpression('/^[a-z]+(_[a-z]+)*$/', $name);
            $this->assertSame($name, $tool->name());
        }
    }

    public function testScopesAreValid(): void
    {
        foreach ($this->tools as $name => $tool) {
            $this->assertContains($tool->scope(), ['read', 'write', 'delete'], $name);
        }
    }

    /** A tool must never be reachable with a weaker scope than its verb implies. */
    public function testScopeMatchesVerb(): void
    {
        $expected = [
            'list_' => 'read', 'get_' => 'read', 'search_' => 'read',
            'create_' => 'write', 'update_' => 'write', 'publish_' => 'write', 'upload_' => 'write', 'move_' => 'write', 'set_' => 'write',
            'delete_' => 'delete', 'remove_' => 'delete', 'purge_' => 'delete',
        ];
        foreach ($this->tools as $name => $tool) {
            $matched = false;
            foreach ($expected as $prefix => $scope) {
                if (str_starts_with($name, $prefix)) {
                    $this->assertSame($scope, $tool->scope(), "$name must require the '$scope' scope");
                    $matched = true;
                }
            }
            $this->assertTrue($matched, "$name has an unknown verb prefix – add it to this test deliberately");
        }
    }

    /** The plugin must not expose administration (sections, fields, plugins, users, settings …). */
    public function testNoAdministrativeWriteTools(): void
    {
        $forbidden = ['section', 'entry_type', 'field', 'plugin', 'user', 'group', 'permission', 'setting', 'config', 'migration', 'project', 'site'];
        foreach ($this->tools as $name => $tool) {
            if ($tool->scope() === 'read') {
                continue;
            }
            foreach ($forbidden as $word) {
                $this->assertStringNotContainsString($word, $name, "Write/delete tool '$name' looks administrative");
            }
        }
    }

    public function testSchemasAreWellFormed(): void
    {
        foreach ($this->tools as $name => $tool) {
            $def = $tool->definition();
            $this->assertNotSame('', trim($def['description']), "$name needs a description");
            $this->assertSame('object', $def['inputSchema']['type']);

            $props = $tool->properties();
            foreach ($tool->required() as $key) {
                $this->assertArrayHasKey($key, $props, "$name: required '$key' is not a property");
            }
            foreach ($props as $key => $schema) {
                $this->assertArrayHasKey('type', $schema, "$name.$key needs a type");
            }
            $this->assertNotFalse(json_encode($def), "$name definition must be JSON-encodable");
        }
    }

    public function testListingLimitIsCapped(): void
    {
        $tool = $this->tools['search_entries'];
        $ref = new \ReflectionMethod($tool, 'limit');
        $ref->setAccessible(true);
        $this->assertSame(100, $ref->invoke($tool, ['limit' => 100000]));
        $this->assertSame(1, $ref->invoke($tool, ['limit' => -5]));
        $this->assertSame(20, $ref->invoke($tool, []));
    }

    public function testDeleteToolsExistOnlyUnderDeleteScope(): void
    {
        $delete = array_filter($this->tools, fn(AbstractTool $t) => $t->scope() === 'delete');
        $this->assertNotEmpty($delete);
        foreach ($delete as $name => $tool) {
            $this->assertStringStartsWith('delete_', $name);
        }
    }
}
