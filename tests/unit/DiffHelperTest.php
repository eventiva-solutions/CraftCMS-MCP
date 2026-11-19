<?php

namespace eventiva\mcp\tests\unit;

use eventiva\mcp\helpers\DiffHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The before/after view is the core of the approval workflow, so its rules are pinned down here.
 */
class DiffHelperTest extends TestCase
{
    private static function text(string $html): string
    {
        // Whitespace is ignored: a word whose trailing space changes cannot keep both spellings in one inline view.
        return preg_replace('/\s+/', '', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Removing everything that was inserted must give the old text, removing everything deleted must give the new text. */
    #[DataProvider('pairs')]
    public function testDiffReconstructsBothTexts(string $old, string $new): void
    {
        $html = DiffHelper::words($old, $new);

        if (str_contains($html, 'mcp-diff-pair')) {
            $this->assertStringContainsString(htmlspecialchars($old, ENT_QUOTES), $html);
            $this->assertStringContainsString(htmlspecialchars($new, ENT_QUOTES), $html);
            return;
        }

        $withoutIns = self::text(preg_replace('#<ins>.*?</ins>#s', '', $html));
        $withoutDel = self::text(preg_replace('#<del>.*?</del>#s', '', $html));
        $this->assertSame(self::text($old), $withoutIns, 'old text must be recoverable');
        $this->assertSame(self::text($new), $withoutDel, 'new text must be recoverable');
    }

    public static function pairs(): array
    {
        return [
            'one word changed' => ['The festival has 12,000 guests today', 'The festival has 15,000 guests today'],
            'word added' => ['Stage and lighting', 'Stage, lighting and sound'],
            'word removed' => ['A very long sentence here', 'A long sentence here'],
            'heavily rewritten' => ['Setup is available as an option.', 'Yes, setup and teardown by our crew can be added to any package.'],
            'completely different' => ['Alpha beta gamma delta', 'one two three four five'],
            'multi paragraph' => ["First line\nSecond line", "First line\nSecond line changed"],
            'umlauts' => ['Größe prüfen', 'Größe sorgfältig prüfen'],
        ];
    }

    public function testSmallChangeIsInline(): void
    {
        $html = DiffHelper::words('We supplied stage, lighting and sound.', 'We supplied stage, lighting, sound and LED screens.');
        $this->assertStringNotContainsString('mcp-diff-pair', $html);
        $this->assertStringContainsString('<ins>', $html);
    }

    public function testHeavyRewriteIsShownAsOldAndNew(): void
    {
        $html = DiffHelper::words('Setup is available as an option.', 'Yes, setup and teardown by our crew can be added to any package.');
        $this->assertStringContainsString('mcp-diff-pair', $html);
        $this->assertSame(0, substr_count($html, '<del>'), 'no confetti of tiny strike-throughs');
    }

    public function testWhitespaceNeverSplitsAChange(): void
    {
        $html = DiffHelper::words('one two three four five six', 'one 2 3 four five six');
        $this->assertSame(1, substr_count($html, '<del>'), 'adjacent changed words form a single change');
        $this->assertSame(1, substr_count($html, '<ins>'));
    }

    public function testOutputIsEscaped(): void
    {
        $html = DiffHelper::words('a <script>alert(1)</script> b', 'a <b>bold</b> b');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;', $html);
    }

    public function testIdenticalAndEmptyInputs(): void
    {
        $this->assertSame('same', DiffHelper::words('same', 'same'));
        $this->assertSame('<ins>new</ins>', DiffHelper::words('', 'new'));
        $this->assertSame('<del>old</del>', DiffHelper::words('old', ''));
    }

    public function testPlainTurnsHtmlIntoText(): void
    {
        $this->assertSame("First\nSecond", DiffHelper::plain('<p>First</p><p>Second</p>'));
        $this->assertSame('a & b', DiffHelper::plain('a &amp; b'));
        $this->assertSame('yes', DiffHelper::plain(true));
        $this->assertSame('', DiffHelper::plain(null));
    }

    public function testCompareSkipsUnchangedAndEmptyEquivalentFields(): void
    {
        $rows = DiffHelper::compare(
            ['title' => 'Same', 'subtitle' => null, 'tags' => [1, 2], 'text' => 'Old text'],
            ['title' => 'Same', 'subtitle' => '', 'tags' => [1, 2], 'text' => 'New text'],
            ['text' => 'Text']
        );
        $this->assertCount(1, $rows);
        $this->assertSame('text', $rows[0]['field']);
        $this->assertSame('Text', $rows[0]['label']);
    }

    public function testMatrixBlocksAreComparedByContentNotById(): void
    {
        // Same content under different block IDs (as it happens between a live entry and its draft) is not a change.
        $live = ['101' => ['type' => 'faq', 'title' => 'Q1', 'fields' => ['answer' => 'A1']]];
        $draft = ['907' => ['type' => 'faq', 'title' => 'Q1', 'fields' => ['answer' => 'A1']]];
        $this->assertSame([], DiffHelper::compare(['faq' => $live], ['faq' => $draft]));
    }

    public function testMatrixDiffMarksNewChangedAndRemovedBlocks(): void
    {
        $live = [
            '1' => ['type' => 'faq', 'title' => 'Q1', 'fields' => ['answer' => 'A1']],
            '2' => ['type' => 'faq', 'title' => 'Q2', 'fields' => ['answer' => 'Old answer here']],
            '3' => ['type' => 'faq', 'title' => 'Q3', 'fields' => ['answer' => 'A3']],
        ];
        $draft = [
            '9' => ['type' => 'faq', 'title' => 'Q1', 'fields' => ['answer' => 'A1']],
            '8' => ['type' => 'faq', 'title' => 'Q2', 'fields' => ['answer' => 'New answer here']],
            'new1' => ['type' => 'faq', 'title' => 'Q4', 'fields' => ['answer' => 'A4']],
        ];
        $rows = DiffHelper::compare(['faq' => $live], ['faq' => $draft]);
        $this->assertCount(1, $rows);
        $html = $rows[0]['html'];
        $this->assertStringContainsString('mcp-block-same', $html);
        $this->assertStringContainsString('mcp-block-changed', $html);
        $this->assertStringContainsString('mcp-block-new', $html);
        $this->assertStringContainsString('mcp-block-removed', $html);
    }

    public function testIsBlockList(): void
    {
        $this->assertTrue(DiffHelper::isBlockList(['1' => ['type' => 'a', 'fields' => []]]));
        $this->assertFalse(DiffHelper::isBlockList([]));
        $this->assertFalse(DiffHelper::isBlockList(['a', 'b']));
        $this->assertFalse(DiffHelper::isBlockList(['x' => ['type' => 'a']]));
    }
}
