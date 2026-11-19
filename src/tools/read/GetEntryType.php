<?php

namespace eventiva\mcp\tools\read;

use Craft;
use craft\fields\BaseOptionsField;
use eventiva\mcp\tools\AbstractTool;

class GetEntryType extends AbstractTool
{
    public function name(): string
    {
        return 'get_entry_type';
    }

    public function description(): string
    {
        return 'Returns an entry type with all custom fields (handle, type, required, instructions, options). Call this before create_entry/update_entry to know which field handles and value formats exist. Without "handle" all entry types are listed briefly.';
    }

    public function properties(): array
    {
        return [
            'handle' => ['type' => 'string', 'description' => 'Entry type handle. Omit to list all.'],
        ];
    }

    public function run(array $args): mixed
    {
        $entries = Craft::$app->getEntries();

        if (empty($args['handle'])) {
            return array_map(fn($t) => ['handle' => $t->handle, 'name' => $t->name], $entries->getAllEntryTypes());
        }

        $type = $entries->getEntryTypeByHandle($args['handle']);
        if (!$type) {
            throw new \InvalidArgumentException("Unknown entry type '{$args['handle']}'. Use list_sections to see available entry types.");
        }

        $fields = [];
        foreach ($type->getFieldLayout()->getCustomFieldElements() as $element) {
            $field = $element->getField();
            $info = [
                'handle' => $field->handle,
                'name' => $element->label ?: $field->name,
                'type' => (new \ReflectionClass($field))->getShortName(),
                'required' => (bool)$element->required,
                'instructions' => $element->instructions ?: $field->instructions,
                'translatable' => $field->getIsTranslatable(null),
            ];
            if ($field instanceof BaseOptionsField) {
                $info['options'] = array_map(fn($o) => ['label' => $o['label'], 'value' => $o['value']], $field->options);
            }
            $fields[] = $info;
        }

        return [
            'handle' => $type->handle,
            'name' => $type->name,
            'hasTitleField' => $type->hasTitleField,
            'fields' => $fields,
        ];
    }
}
