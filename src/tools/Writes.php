<?php

namespace eventiva\mcp\tools;

use Craft;
use craft\base\ElementInterface;

trait Writes
{
    /**
     * Validates field handles against the element's field layout and sets the values.
     */
    protected function applyFields(ElementInterface $element, mixed $fields): void
    {
        if ($fields === null || $fields === []) {
            return;
        }
        if (!is_array($fields)) {
            throw new \InvalidArgumentException('"fields" must be an object of fieldHandle => value.');
        }

        $valid = [];
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $valid[] = $field->handle;
        }
        $unknown = array_diff(array_keys($fields), $valid);
        if ($unknown) {
            throw new \InvalidArgumentException(
                'Unknown field handle(s): ' . implode(', ', $unknown) . '. Valid handles: ' . implode(', ', $valid) . '. See get_entry_type.'
            );
        }

        $element->setFieldValues($fields);
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
            'description' => 'Custom field values keyed by field handle (see get_entry_type). Text: string; rich text: HTML string; dropdown/radio: option value; lightswitch: boolean; date: ISO 8601 string; relations/assets/categories/tags: array of element IDs; table: array of row objects.',
            'additionalProperties' => true,
        ];
    }
}
