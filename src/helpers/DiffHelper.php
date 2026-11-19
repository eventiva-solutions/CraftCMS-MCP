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

    /** Word level diff as HTML with <del> / <ins>. */
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

        $a = preg_split('/(\s+)/u', $old, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $b = preg_split('/(\s+)/u', $new, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($a);
        $m = count($b);

        if ($n * $m > self::MAX_LCS_CELLS) {
            return '<del>' . self::e($old) . '</del> <ins>' . self::e($new) . '</ins>';
        }

        // Longest common subsequence table (rows: old tokens, cols: new tokens)
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $out = '';
        $del = '';
        $ins = '';
        $flush = function () use (&$out, &$del, &$ins) {
            if ($del !== '') {
                $out .= '<del>' . self::e($del) . '</del>';
            }
            if ($ins !== '') {
                $out .= '<ins>' . self::e($ins) . '</ins>';
            }
            $del = $ins = '';
        };

        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $flush();
                $out .= self::e($a[$i]);
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $del .= $a[$i++];
            } else {
                $ins .= $b[$j++];
            }
        }
        while ($i < $n) {
            $del .= $a[$i++];
        }
        while ($j < $m) {
            $ins .= $b[$j++];
        }
        $flush();

        return $out;
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
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
        }
        return $text;
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
            if (isset($oldList[$i]) && empty($used[$i]) && ($oldList[$i]['type'] ?? null) === ($block['type'] ?? null)) {
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
