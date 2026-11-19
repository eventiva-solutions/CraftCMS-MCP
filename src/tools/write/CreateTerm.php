<?php

namespace eventiva\mcp\tools\write;

use Craft;
use craft\elements\Category;
use craft\elements\Tag;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\TermHelper;
use eventiva\mcp\tools\Writes;

class CreateTerm extends AbstractTool
{
    use TermHelper, Writes;

    public function name(): string
    {
        return 'create_term';
    }

    public function description(): string
    {
        return 'Creates a category or tag. Search first (search_terms) to avoid duplicates.';
    }

    public function scope(): string
    {
        return 'write';
    }

    /** Not reviewable: there is no draft mechanism for this, so it is unavailable while approval is required. */
    public function availableInReview(): bool
    {
        return false;
    }

    public function properties(): array
    {
        return [
            'type' => $this->typeProperty(),
            'group' => ['type' => 'string', 'description' => 'Group handle (see list_taxonomies).'],
            'title' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'parentId' => ['type' => 'integer', 'description' => 'Parent category ID (categories only).'],
            'fields' => $this->fieldsProperty(),
        ];
    }

    public function required(): array
    {
        return ['type', 'group', 'title'];
    }

    public function run(array $args): mixed
    {
        if ($args['type'] === 'category') {
            $group = Craft::$app->getCategories()->getGroupByHandle($args['group']);
            $term = new Category();
            if (!empty($args['parentId'])) {
                $term->setParentId((int)$args['parentId']);
            }
        } elseif ($args['type'] === 'tag') {
            $group = Craft::$app->getTags()->getTagGroupByHandle($args['group']);
            $term = new Tag();
        } else {
            throw new \InvalidArgumentException("type must be 'category' or 'tag'.");
        }
        if (!$group) {
            throw new \InvalidArgumentException("Unknown group '{$args['group']}'. Use list_taxonomies.");
        }

        $term->groupId = $group->id;
        $term->title = $args['title'];
        if (!empty($args['slug'])) {
            $term->slug = $args['slug'];
        }
        $this->applyFields($term, $args['fields'] ?? null);

        $this->assertCanSave($term);
        $this->saveOrFail($term);

        return $this->termSummary($term);
    }
}
