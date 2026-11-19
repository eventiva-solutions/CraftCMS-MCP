<?php

namespace eventiva\mcp\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Every UI string used in the plugin must have a German translation, and the translation file must not
 * contain strings that are no longer used. The English source strings are the keys.
 */
class TranslationsTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src';

    /** @return array<string, string> */
    private function german(): array
    {
        return require self::SRC . '/translations/de/mcp-server.php';
    }

    /** @return string[] */
    private function usedStrings(): array
    {
        $patterns = [
            "/Craft::t\\(\\s*'mcp-server',\\s*'((?:[^'\\\\]|\\\\.)*)'/",
            "/self::t\\('((?:[^'\\\\]|\\\\.)*)'\\)/",
            "/'((?:[^'\\\\]|\\\\.)*)'\\s*\\|\\s*t\\(\\s*'mcp-server'/",
        ];

        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (str_contains($path, 'translations') || !preg_match('/\.(php|twig)$/', $path)) {
                continue;
            }
            $text = file_get_contents($path);
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $text, $matches)) {
                    foreach ($matches[1] as $string) {
                        $found[str_replace("\\'", "'", $string)] = true;
                    }
                }
            }
        }
        return array_keys($found);
    }

    public function testStringsAreFound(): void
    {
        $this->assertGreaterThan(50, count($this->usedStrings()), 'The scanner should find the UI strings');
    }

    public function testEveryUsedStringHasAGermanTranslation(): void
    {
        $german = $this->german();
        $missing = array_values(array_filter($this->usedStrings(), fn(string $s) => !array_key_exists($s, $german)));
        $this->assertSame([], $missing, "Missing German translations:\n" . implode("\n", $missing));
    }

    public function testNoStaleTranslations(): void
    {
        $stale = array_values(array_diff(array_keys($this->german()), $this->usedStrings()));
        $this->assertSame([], $stale, "Translations for strings that are not used anymore:\n" . implode("\n", $stale));
    }

    public function testTranslationsAreNotEmptyAndKeepPlaceholders(): void
    {
        foreach ($this->german() as $english => $german) {
            $this->assertNotSame('', trim($german), "Empty translation for: $english");
            preg_match_all('/\{(\w+)[,}]/', $english, $a);
            preg_match_all('/\{(\w+)[,}]/', $german, $b);
            $this->assertEqualsCanonicalizing($a[1], $b[1], "Placeholders differ for: $english");
        }
    }

    public function testNoGermanLeftInSourceStrings(): void
    {
        foreach ($this->usedStrings() as $string) {
            $this->assertDoesNotMatchRegularExpression('/[äöüÄÖÜß]/u', $string, "Source string must be English: $string");
        }
    }
}
