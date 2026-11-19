<?php

namespace eventiva\mcp\tests\unit;

use eventiva\mcp\services\ProtocolService;
use eventiva\mcp\tools\AbstractTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MCP clients do not always send correctly typed arguments (booleans as "false",
 * arrays as JSON strings). The helpers must never turn "false" into true.
 */
class ArgumentHelpersTest extends TestCase
{
    private AbstractTool $tool;

    protected function setUp(): void
    {
        $this->tool = (new ProtocolService())->tools()['update_entry'];
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($this->tool, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->tool, ...$args);
    }

    #[DataProvider('booleans')]
    public function testBoolArg(mixed $input, bool $expected): void
    {
        $this->assertSame($expected, $this->call('boolArg', $input));
    }

    public static function booleans(): array
    {
        return [
            'true' => [true, true],
            'false' => [false, false],
            'string true' => ['true', true],
            'string TRUE' => ['TRUE', true],
            'string 1' => ['1', true],
            'string yes' => ['yes', true],
            'string false' => ['false', false],
            'string FALSE' => [' False ', false],
            'string 0' => ['0', false],
            'empty string' => ['', false],
            'int 1' => [1, true],
            'int 0' => [0, false],
        ];
    }

    public function testBoolArgDefault(): void
    {
        $this->assertTrue($this->call('boolArg', null, true));
        $this->assertFalse($this->call('boolArg', null, false));
        $this->assertFalse($this->call('boolArg', null));
    }

    public function testJsonArgDecodesJsonStringsOnly(): void
    {
        $this->assertSame(['a' => 1], $this->call('jsonArg', '{"a":1}'));
        $this->assertSame([1, 2], $this->call('jsonArg', ' [1, 2] '));
        $this->assertSame('plain', $this->call('jsonArg', 'plain'));
        $this->assertSame('{not json', $this->call('jsonArg', '{not json'));
        $this->assertSame(['x'], $this->call('jsonArg', ['x']));
        $this->assertNull($this->call('jsonArg', null));
    }

    public function testStringListAcceptsAllShapes(): void
    {
        $this->assertSame(['a', 'b'], $this->call('stringList', ['a', 'b']));
        $this->assertSame(['a', 'b'], $this->call('stringList', '["a","b"]'));
        $this->assertSame(['a', 'b'], $this->call('stringList', 'a, b'));
        $this->assertSame(['a'], $this->call('stringList', 'a'));
        $this->assertNull($this->call('stringList', ''));
        $this->assertNull($this->call('stringList', []));
        $this->assertNull($this->call('stringList', null));
    }
}
