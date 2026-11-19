<?php

namespace eventiva\mcp\helpers;

/**
 * Builds a human readable "before / after" view of serialized field values.
 * Pure PHP (no Craft needed) so it can be unit tested. All output is HTML-escaped.
 */
class DiffHelper
{
    /** Above this many token pairs a word diff gets too expensive; show old and new instead. */
    private const MAX_LCS_CELLS = 2_000_000;

    /**
     * Compares two maps of serialized values and returns one row per changed field.
     *
     * @param array<string, mixed> $old handle => value
     * @param array<string, mixed> $new handle => value
     * @param array<string, string> $labels handle => human label
     * @return array<int, array{field: string, label: string, html: string}>
     */
    public static function compare(array $old, array $new, array $labels = []): array
    {
        $rows = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $handle) {
            $a = $old[$handle] ?? null;
            $b = $new[$handle] ?? null;
            if (self::same($a, $b)) {
                continue;
            }
            $rows[] = [
                'field' => (string)$handle,
                'label' => $labels[$handle] ?? (string)$handle,
                'html' => self::renderValue($a, $b, $labels),
            ];
        }
        return $rows;
    }

    /** Renders the difference of one value (text, scalar, list or Matrix blocks). */
    public static function renderValue(mixed $old, mixed $new, array $labels = []): string
    {
        if (self::isBlockList($old) || self::isBlockList($new)) {
            return self::renderBlocks(self::isBlockList($old) ? $old : [], self::isBlockList($new) ? $new : []);
        }

        $a = self::plain($old);
        $b = self::plain($new);
        if (is_array($old) || is_array($new)) {
            return '<div class="mcp-diff-pair"><div class="mcp-diff-old">' . self::e($a) . '</div><div class="mcp-diff-new">' . self::e($b) . '</div></div>';
        }
        return '<div class="mcp-diff-text">' . self::words($a, $b) . '</div>';
    }

    /**
     * Word level diff as HTML with <del> / <ins>.
     *
     * Whitespace belongs to the word before it, so it never splits a change into pieces. Very short common words
     * between two changes are folded into the change, and heavily rewritten text is shown as "old / new" instead of
     * an unreadable mix of tiny strike-throughs.
     */
    public static function words(string $old, string $new): string
    {
        if ($old === $new) {
            return self::e($new);
        }
        if ($old === '') {
            return '<ins>' . self::e($new) . '</ins>';
        }
        if ($new === '') {
            return '<del>' . self::e($old) . '</del>';
        }

        $a = self::tokens($old);
        $b = self::tokens($new);
        $n = count($a);
        $m = count($b);

        if ($n * $m > self::MAX_LCS_CELLS) {
            return self::pair($old, $new);
        }

        $keyA = array_map('trim', $a);
        $keyB = array_map('trim', $b);

        // Longest common subsequence table (rows: old tokens, cols: new tokens)
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $keyA[$i] === $keyB[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        // Operations: '=' common, '-' removed, '+' added
        $ops = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($keyA[$i] === $keyB[$j]) {
                $ops[] = ['=', $b[$j]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $ops[] = ['-', $a[$i++]];
            } else {
                $ops[] = ['+', $b[$j++]];
            }
        }
        while ($i < $n) {
            $ops[] = ['-', $a[$i++]];
        }
        while ($j < $m) {
            $ops[] = ['+', $b[$j++]];
        }

        // Fold short common words that sit between two changes into the change ("x" = shown as removed AND added)
        $count = count($ops);
        for ($k = 1; $k < $count - 1; $k++) {
            if ($ops[$k][0] === '=' && $ops[$k - 1][0] !== '=' && $ops[$k + 1][0] !== '=' && mb_strlen(trim($ops[$k][1])) <= 3) {
                $ops[$k][0] = 'x';
            }
        }

        // Mostly rewritten: show old and new instead of a confetti of changes
        $common = 0;
        foreach ($ops as [$kind]) {
            $common += $kind === '=' ? 1 : 0;
        }
        if (max($n, $m) >= 4 && $common / max($n, $m) < 0.4) {
            return self::pair($old, $new);
        }

        $out = '';
        $del = '';
        $ins = '';
        $flush = function () use (&$out, &$del, &$ins) {
            if ($del !== '') {
                $out .= '<del>' . self::e(rtrim($del)) . '</del>' . (strlen($del) !== strlen(rtrim($del)) ? ' ' : '');
            }
            if ($ins !== '') {
                $out .= '<ins>' . self::e(rtrim($ins)) . '</ins>' . (strlen($ins) !== strlen(rtrim($ins)) ? ' ' : '');
            }
            $del = $ins = '';
        };
        foreach ($ops as [$kind, $token]) {
            if ($kind === '=') {
                $flush();
                $out .= self::e($token);
            } elseif ($kind === '-') {
                $del .= $token;
            } elseif ($kind === '+') {
                $ins .= $token;
            } else {
                $del .= $token;
                $ins .= $token;
            }
        }
        $flush();

        return $out;
    }

    /**
     * Words and punctuation as separate tokens, each with its trailing whitespace attached,
     * so whitespace is never matched on its own and "lighting," still matches "lighting".
     */
    private static function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}_\'’-]+\s*|[^\p{L}\p{N}\s]+\s*|\s+/u', $text, $matches);
        return $matches[0];
    }

    /** Old and new text one below the other. */
    private static function pair(string $old, string $new): string
    {
        return '<div class="mcp-diff-pair"><div class="mcp-diff-old">' . self::e($old) . '</div><div class="mcp-diff-new">' . self::e($new) . '</div></div>';
    }

    /** Turns any value into display text. HTML becomes text with line breaks at block boundaries. */
    public static function plain(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? self::t('yes') : self::t('no');
        }
        if (is_array($value)) {
            return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        $text = (string)$value;
        if ($text !== strip_tags($text)) {
            $text = preg_replace('#<\s*(br|/p|/div|/li|/h[1-6]|/tr)\s*/?>#i', "\n", $text) ?? $text;
            $text = preg_replace('#<\s*li[^>]*>#i', '• ', $text) ?? $text;
            $text = strip_tags($text);
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    /** True for the serialized form of a Matrix field: a map of blocks that each have "type" and "fields". */
    public static function isBlockList(mixed $value): bool
    {
        if (!is_array($value) || $value === []) {
            return false;
        }
        foreach ($value as $block) {
            if (!is_array($block) || !array_key_exists('type', $block) || !array_key_exists('fields', $block)) {
                return false;
            }
        }
        return true;
    }

    /** Matrix diff: unchanged blocks are collapsed, changed blocks show their field diff, new/removed are marked. */
    private static function renderBlocks(array $old, array $new): string
    {
        $oldList = array_values($old);
        $newList = array_values($new);
        $sig = fn(array $b) => md5((string)json_encode(self::normalize([$b['type'] ?? '', $b['title'] ?? '', $b['enabled'] ?? true, $b['fields'] ?? []])));

        $oldBySig = [];
        foreach ($oldList as $i => $block) {
            $oldBySig[$sig($block)][] = $i;
        }
        $used = [];
        $html = '';

        foreach ($newList as $i => $block) {
            $s = $sig($block);
            $label = self::blockLabel($block);

            // identical block somewhere in the old list
            if (!empty($oldBySig[$s])) {
                $oldIndex = array_shift($oldBySig[$s]);
                $used[$oldIndex] = true;
                $html .= '<div class="mcp-block mcp-block-same"><span class="mcp-tag">' . self::t('unchanged') . '</span> ' . self::e($label) . '</div>';
                continue;
            }

            // same position and type: show what changed inside
            if (isset($oldList[$i]) && empty($used[$i]) && ($oldList[$i]['type'] ?? null) === ($block['type'] ?? null) && self::sharesContent($oldList[$i], $block)) {
                $used[$i] = true;
                $html .= '<div class="mcp-block mcp-block-changed"><span class="mcp-tag mcp-tag-changed">' . self::t('changed') . '</span> ' . self::e($label)
                    . self::blockFields($oldList[$i], $block) . '</div>';
                continue;
            }

            $html .= '<div class="mcp-block mcp-block-new"><span class="mcp-tag mcp-tag-new">' . self::t('new') . '</span> ' . self::e($label)
                . self::blockFields([], $block) . '</div>';
        }

        foreach ($oldList as $i => $block) {
            if (empty($used[$i])) {
                $html .= '<div class="mcp-block mcp-block-removed"><span class="mcp-tag mcp-tag-removed">' . self::t('removed') . '</span> <del>' . self::e(self::blockLabel($block)) . '</del>'
                    . self::blockFields($block, [], true) . '</div>';
            }
        }

        return $html;
    }

    /** True if the title or at least one field is identical, i.e. the new block is an edit of the old one, not a replacement. */
    private static function sharesContent(array $old, array $new): bool
    {
        if (trim((string)($old['title'] ?? '')) !== '' && ($old['title'] ?? null) === ($new['title'] ?? null)) {
            return true;
        }
        foreach ($new['fields'] ?? [] as $handle => $value) {
            if (array_key_exists($handle, $old['fields'] ?? []) && !self::isEmptyValue($value) && self::same($old['fields'][$handle], $value)) {
                return true;
            }
        }
        // Blocks without any content (e.g. a headline block without fields) are the same block.
        return !($old['fields'] ?? []) && !($new['fields'] ?? []) && ($old['title'] ?? '') === ($new['title'] ?? '');
    }

    private static function isEmptyValue(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [];
    }

    private static function blockLabel(array $block): string
    {
        $title = trim((string)($block['title'] ?? ''));
        return ($block['type'] ?? 'block') . ($title !== '' ? ': ' . $title : '');
    }

    private static function blockFields(array $old, array $new, bool $removed = false): string
    {
        $oldFields = $old['fields'] ?? [];
        $newFields = $new['fields'] ?? [];
        $html = '';

        if (($old['title'] ?? null) !== ($new['title'] ?? null) && $old && $new) {
            $html .= '<div class="mcp-diff-field"><strong>' . self::t('Title') . '</strong><div class="mcp-diff-text">'
                . self::words((string)($old['title'] ?? ''), (string)($new['title'] ?? '')) . '</div></div>';
        }

        foreach (array_unique(array_merge(array_keys($oldFields), array_keys($newFields))) as $handle) {
            $a = $oldFields[$handle] ?? null;
            $b = $newFields[$handle] ?? null;
            if (self::same($a, $b)) {
                continue;
            }
            $html .= '<div class="mcp-diff-field"><strong>' . self::e((string)$handle) . '</strong>'
                . ($removed ? '<div class="mcp-diff-text"><del>' . self::e(self::plain($a)) . '</del></div>' : self::renderValue($a, $b))
                . '</div>';
        }
        return $html;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        // null, "" and [] are all "empty" – a field that goes from null to "" is not a change worth reviewing
        $empty = fn($v) => $v === null || $v === '' || $v === [];
        if ($empty($a) && $empty($b)) {
            return true;
        }
        return json_encode(self::normalize($a)) === json_encode(self::normalize($b));
    }

    /**
     * Matrix blocks get new IDs in a draft. Drop the IDs (keys of block lists) before comparing,
     * recursively, so identical content compares equal.
     */
    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (self::isBlockList($value)) {
            return array_map(fn($block) => self::normalize([
                'type' => $block['type'],
                'title' => $block['title'] ?? '',
                'enabled' => $block['enabled'] ?? true,
                'fields' => $block['fields'],
            ]), array_values($value));
        }
        return array_map(fn($v) => self::normalize($v), $value);
    }

    /** Translates a UI word. Falls back to the English source text when Craft is not loaded (unit tests). */
    private static function t(string $message): string
    {
        return class_exists(\Craft::class) ? \Craft::t('mcp-server', $message) : $message;
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
