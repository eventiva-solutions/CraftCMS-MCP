<?php

namespace eventiva\mcp\tools\write;

use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\TermHelper;
use eventiva\mcp\tools\Writes;

class UpdateTerm extends AbstractTool
{
    use TermHelper, Writes;

    public function name(): string
    {
        return 'update_term';
    }

    public function description(): string
    {
        return 'Updates a category or tag (title, slug, enabled, custom fields; parentId for categories).';
    }

    public function scope(): string
    {
        return 'write';
    }

    public function properties(): array
    {
        return [
            'type' => $this->typeProperty(),
            'id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'enabled' => ['type' => 'boolean'],
            'parentId' => ['type' => 'integer', 'description' => 'Categories only; 0 = root.'],
            'fields' => $this->fieldsProperty(),
        ];
    }

    public function required(): array
    {
        return ['type', 'id'];
    }

    public function run(array $args): mixed
    {
        $class = $this->termClass($args['type']);
        $term = $class::find()->id((int)$args['id'])->status(null)->one();
        if (!$term) {
            throw new \InvalidArgumentException("{$args['type']} {$args['id']} not found.");
        }

        if (array_key_exists('title', $args)) {
            $term->title = (string)$args['title'];
        }
        if (array_key_exists('slug', $args)) {
            $term->slug = (string)$args['slug'];
        }
        if (array_key_exists('enabled', $args)) {
            $term->enabled = (bool)$args['enabled'];
        }
        if (array_key_exists('parentId', $args) && $args['type'] === 'category') {
            $term->setParentId((int)$args['parentId'] ?: null);
        }
        $this->applyFields($term, $args['fields'] ?? null);

        $this->assertCanSave($term);
        $this->saveOrFail($term);

        return $this->termSummary($term);
    }
}
