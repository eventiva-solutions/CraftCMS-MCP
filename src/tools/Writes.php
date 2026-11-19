<?php

namespace eventiva\mcp\tools;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\helpers\ArrayHelper;

trait Writes
{
    /** Matrix fields touched by the last applyFields() call (handle => field). */
    private array $touchedMatrix = [];

    /**
     * Validates field handles against the element's field layout and sets the values.
     * Matrix values are validated and normalized first, because Craft silently drops
     * unknown block types / field handles and removes blocks that are not sent again.
     */
    protected function applyFields(ElementInterface $element, mixed $fields, bool $allowBlockRemoval = false): void
    {
        $this->touchedMatrix = [];

        if ($fields === null || $fields === []) {
            return;
        }
        if (!is_array($fields)) {
            throw new \InvalidArgumentException('"fields" must be an object of fieldHandle => value.');
        }

        $byHandle = [];
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $byHandle[$field->handle] = $field;
        }
        $unknown = array_diff(array_keys($fields), array_keys($byHandle));
        if ($unknown) {
            throw new \InvalidArgumentException(
                'Unknown field handle(s): ' . implode(', ', $unknown) . '. Valid handles: ' . implode(', ', array_keys($byHandle)) . '. See get_entry_type.'
            );
        }

        foreach ($fields as $handle => $value) {
            if ($byHandle[$handle] instanceof Matrix) {
                $fields[$handle] = $this->prepareMatrixValue($byHandle[$handle], $value, $element, $allowBlockRemoval);
                $this->touchedMatrix[$handle] = $byHandle[$handle];
            }
        }

        $element->setFieldValues($fields);
    }

    /** Block counts per Matrix field that was written, e.g. ['faq' => 3] (empty if none). */
    protected function matrixReport(ElementInterface $element): array
    {
        $report = [];
        foreach ($this->touchedMatrix as $handle => $field) {
            $report[$handle] = (int)Entry::find()->fieldId($field->id)->owner($element)->drafts(null)->status(null)->count();
        }
        return $report;
    }

    /**
     * Turns the value the model sent for a Matrix field into the format Craft expects, after validating it.
     *
     * Accepted input:
     *  - a list of blocks:            [{"id": 12, "fields": {...}}, {"type": "faq", "title": "...", "fields": {...}}]
     *  - the object returned by get_entry (keyed by block ID, "new1", "new2" ... for new blocks)
     *  - {"mode": "replace"|"append", "blocks": [...]}
     * Blocks with an "id" update that existing block; blocks without "id" are created and need a "type".
     */
    private function prepareMatrixValue(Matrix $field, mixed $value, ElementInterface $element, bool $allowRemoval): array
    {
        $handle = $field->handle;
        if ($value === null || $value === '') {
            $value = [];
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException("Matrix field '$handle' needs a list of blocks. See get_entry_type for the allowed block types.");
        }

        $mode = 'replace';
        $blocks = $value;
        if (isset($value['blocks']) && is_array($value['blocks'])) {
            $mode = (string)($value['mode'] ?? 'replace');
            $blocks = $value['blocks'];
        } elseif (isset($value['entries']) && is_array($value['entries'])) {
            // Craft's delta format
            $blocks = $value['entries'];
            if (isset($value['sortOrder']) && is_array($value['sortOrder'])) {
                $ordered = [];
                foreach ($value['sortOrder'] as $key) {
                    $ordered[$key] = $blocks[$key] ?? [];
                }
                $blocks = $ordered;
            }
        }
        if (!in_array($mode, ['replace', 'append'], true)) {
            throw new \InvalidArgumentException("Matrix field '$handle': mode must be 'replace' or 'append'.");
        }

        // Normalize to a list of [existingId|null, data]
        $list = [];
        if (array_is_list($blocks)) {
            foreach ($blocks as $block) {
                $id = is_array($block) && isset($block['id']) ? (int)$block['id'] : null;
                $list[] = [$id, $block];
            }
        } else {
            foreach ($blocks as $key => $block) {
                $list[] = [ctype_digit((string)$key) ? (int)$key : null, $block];
            }
        }

        /** @var Entry[] $existing */
        $existing = [];
        if ($element->id) {
            foreach (Entry::find()->fieldId($field->id)->owner($element)->drafts(null)->status(null)->all() as $entry) {
                $existing[$entry->id] = $entry;
            }
        }

        $types = ArrayHelper::index($field->getEntryTypes(), 'handle');
        $allowed = implode(', ', array_keys($types));

        foreach ($list as $position => [$id, $block]) {
            $label = 'Block #' . ($position + 1) . " of '$handle'";
            if (!is_array($block)) {
                throw new \InvalidArgumentException("$label must be an object with type/title/fields.");
            }
            if ($id !== null) {
                if (!isset($existing[$id])) {
                    throw new \InvalidArgumentException(
                        "$label: block id $id does not exist in this field of this entry" .
                        ($existing ? ' (existing ids: ' . implode(', ', array_keys($existing)) . ')' : ' (the field has no blocks yet)') .
                        '. Omit the id to create a new block. When editing a draft, use the block ids returned by get_entry for the draft.'
                    );
                }
                $type = $existing[$id]->getType();
            } else {
                $typeHandle = $block['type'] ?? null;
                if (!$typeHandle) {
                    throw new \InvalidArgumentException("$label needs a 'type'. Allowed block types: $allowed.");
                }
                if (!isset($types[$typeHandle])) {
                    throw new \InvalidArgumentException("$label: unknown block type '$typeHandle'. Allowed block types: $allowed.");
                }
                $type = $types[$typeHandle];
            }

            if (isset($block['fields']) && !is_array($block['fields'])) {
                throw new \InvalidArgumentException("$label: 'fields' must be an object of fieldHandle => value.");
            }
            $valid = array_map(fn($f) => $f->handle, $type->getFieldLayout()->getCustomFields());
            $unknownInBlock = array_diff(array_keys($block['fields'] ?? []), $valid);
            if ($unknownInBlock) {
                throw new \InvalidArgumentException(
                    "$label (type '{$type->handle}'): unknown field(s) " . implode(', ', $unknownInBlock) .
                    '. Valid fields: ' . implode(', ', $valid) . '.'
                );
            }

            // Nested Matrix fields inside a block get the same validation.
            $blockFields = [];
            foreach ($type->getFieldLayout()->getCustomFields() as $blockField) {
                $blockFields[$blockField->handle] = $blockField;
            }
            foreach ($block['fields'] ?? [] as $fieldHandle => $fieldValue) {
                if ($blockFields[$fieldHandle] instanceof Matrix) {
                    $owner = $id !== null ? $existing[$id] : new Entry();
                    $block['fields'][$fieldHandle] = $this->prepareMatrixValue($blockFields[$fieldHandle], $fieldValue, $owner, $allowRemoval);
                }
            }
            $list[$position][1] = $block;
        }

        // Existing blocks that are not part of the new list would be deleted by Craft.
        if ($mode === 'replace' && $existing) {
            $kept = array_filter(array_column($list, 0), fn($id) => $id !== null);
            $removed = array_diff_key($existing, array_flip($kept));
            if ($removed && !$allowRemoval) {
                $names = array_map(fn(Entry $e) => $e->id . " ('" . mb_strimwidth((string)$e->title, 0, 40, '...') . "')", array_values($removed));
                throw new \RuntimeException(
                    count($removed) . " existing block(s) of '$handle' would be DELETED by this update: " . implode(', ', $names) .
                    '. Nothing was changed. Send these blocks again with their "id" to keep them, use {"mode": "append", "blocks": [...]} to only add new blocks, or pass allowBlockRemoval=true if the removal is intended.'
                );
            }
        }

        // Build Craft's format: blocks keyed by existing id or "newN", in the desired order.
        $out = [];
        if ($mode === 'append') {
            foreach ($existing as $id => $entry) {
                $out[$id] = [];
            }
        }
        $new = 0;
        foreach ($list as [$id, $block]) {
            $data = array_intersect_key($block, array_flip(['title', 'slug', 'enabled', 'fields']));
            if ($id !== null) {
                $out[$id] = $data;
            } else {
                $data['type'] = $block['type'];
                $out['new' . (++$new)] = $data;
            }
        }
        return $out;
    }

    protected function saveOrFail(ElementInterface $element): void
    {
        if (!Craft::$app->getElements()->saveElement($element)) {
            $errors = [];
            foreach ($element->getErrors() as $attr => $messages) {
                $errors[] = $attr . ': ' . implode(' ', $messages);
            }
            throw new \RuntimeException('Saving failed – ' . ($errors ? implode(' | ', $errors) : 'unknown error'));
        }

        // Craft indexes via the queue; index right away so search finds the content immediately.
        try {
            Craft::$app->getSearch()->indexElementAttributes($element);
        } catch (\Throwable $e) {
            Craft::warning('MCP: search indexing failed: ' . $e->getMessage(), __METHOD__);
        }
    }

    protected function assertCanSave(ElementInterface $element): void
    {
        if (!Craft::$app->getElements()->canSave($element, $this->user())) {
            throw new \RuntimeException('The token user is not allowed to save this element.');
        }
    }

    protected function assertCanDelete(ElementInterface $element): void
    {
        if (!Craft::$app->getElements()->canDelete($element, $this->user())) {
            throw new \RuntimeException('The token user is not allowed to delete this element.');
        }
    }

    protected function parseDate(mixed $value): \DateTime
    {
        try {
            return new \DateTime((string)$value);
        } catch (\Throwable) {
            throw new \InvalidArgumentException("Invalid date '$value'. Use ISO 8601, e.g. 2026-01-31T10:00:00+01:00.");
        }
    }

    /** Common JSON schema entry for the "fields" argument. */
    protected function fieldsProperty(): array
    {
        return [
            'type' => 'object',
            'description' => 'Custom field values keyed by field handle (see get_entry_type; omitted fields stay untouched). Text: string; rich text: HTML string; dropdown/radio: option value; lightswitch: boolean; date: ISO 8601 string; relations/assets/categories/tags: array of element IDs; table: array of row objects. '
                . 'Matrix: a list of blocks, e.g. [{"id": 123, "fields": {...}}, {"type": "faq", "title": "...", "fields": {...}}]. Blocks WITH "id" update that block, blocks WITHOUT "id" are created (type required). '
                . 'A Matrix value REPLACES the whole field: existing blocks that are not sent again would be deleted (the call is refused unless allowBlockRemoval=true). To only add blocks use {"mode": "append", "blocks": [...]}. Read the current blocks with get_entry first.',
            'additionalProperties' => true,
        ];
    }

    protected function allowBlockRemovalProperty(): array
    {
        return [
            'type' => 'boolean',
            'description' => 'Set true only if existing Matrix blocks that are missing from "fields" may be deleted. Default false: such a call is refused and nothing is changed.',
        ];
    }
}
