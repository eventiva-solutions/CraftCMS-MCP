<?php

namespace eventiva\mcp\tools\read;

use Craft;
use craft\base\FieldInterface;
use craft\fields\BaseOptionsField;
use craft\fields\BaseRelationField;
use craft\fields\Checkboxes;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Email;
use craft\fields\Lightswitch;
use craft\fields\Matrix;
use craft\fields\MultiSelect;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\RadioButtons;
use craft\fields\Table;
use craft\fields\Url;
use craft\models\FieldLayout;
use eventiva\mcp\tools\AbstractTool;

class GetEntryType extends AbstractTool
{
    public function name(): string
    {
        return 'get_entry_type';
    }

    public function description(): string
    {
        return 'Returns an entry type with all custom fields: handle, type, valueFormat (how to send a value), required, instructions and options. Matrix fields list their allowed block types with the fields of each block type. Call this before create_entry/update_entry. Without "handle" all entry types are listed briefly.';
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

        return [
            'handle' => $type->handle,
            'name' => $type->name,
            'hasTitleField' => $type->hasTitleField,
            'fields' => $this->describeLayout($type->getFieldLayout()),
        ];
    }

    private function describeLayout(FieldLayout $layout, int $depth = 0): array
    {
        $fields = [];
        foreach ($layout->getCustomFieldElements() as $element) {
            $field = $element->getField();
            $info = [
                'handle' => $field->handle,
                'name' => $element->label ?: $field->name,
                'type' => (new \ReflectionClass($field))->getShortName(),
                'required' => (bool)$element->required,
                'instructions' => $element->instructions ?: $field->instructions,
                'translatable' => $field->getIsTranslatable(null),
            ];
            if ($hint = $this->valueFormat($field)) {
                $info['valueFormat'] = $hint;
            }
            if ($field instanceof BaseOptionsField) {
                $info['options'] = array_map(fn($o) => ['label' => $o['label'], 'value' => $o['value']], $field->options);
            }
            if ($field instanceof Matrix && $depth < 2) {
                $info['blockTypes'] = array_map(fn($blockType) => [
                    'handle' => $blockType->handle,
                    'name' => $blockType->name,
                    'hasTitleField' => $blockType->hasTitleField,
                    'fields' => $this->describeLayout($blockType->getFieldLayout(), $depth + 1),
                ], $field->getEntryTypes());
            }
            $fields[] = $info;
        }
        return $fields;
    }

    /** A short, model-friendly description of the value format a field expects. */
    private function valueFormat(FieldInterface $field): ?string
    {
        $class = strtolower(get_class($field));

        return match (true) {
            $field instanceof PlainText, $field instanceof Email, $field instanceof Url => 'string',
            $field instanceof Number => 'number',
            $field instanceof Lightswitch => 'boolean',
            $field instanceof Date => 'ISO 8601 date string',
            $field instanceof Dropdown, $field instanceof RadioButtons => 'one option value (see options)',
            $field instanceof Checkboxes, $field instanceof MultiSelect => 'array of option values (see options)',
            $field instanceof Table => 'array of row objects keyed by column handle',
            $field instanceof BaseRelationField => 'array of element IDs',
            $field instanceof Matrix => 'list of blocks, see blockTypes. A Matrix value replaces all blocks: send existing blocks again with their "id" (see update_entry)',
            str_contains($class, 'ckeditor'), str_contains($class, 'redactor'), str_contains($class, 'article') => 'HTML string (rich text)',
            str_contains($class, 'seomatic') => 'object; call get_entry with fields:["<handle>"] to see the structure (SEO title and description are under metaGlobalVars)',
            default => null,
        };
    }
}
