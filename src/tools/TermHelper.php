<?php

namespace eventiva\mcp\tools;

use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\Tag;

/**
 * Shared helpers for categories and tags ("terms").
 */
trait TermHelper
{
    protected function termClass(string $type): string
    {
        return match ($type) {
            'category' => Category::class,
            'tag' => Tag::class,
            default => throw new \InvalidArgumentException("type must be 'category' or 'tag'."),
        };
    }

    protected function termSummary(ElementInterface $term): array
    {
        return [
            'id' => $term->id,
            'type' => $term instanceof Category ? 'category' : 'tag',
            'title' => $term->title,
            'slug' => $term->slug,
            'group' => $term->getGroup()->handle,
            'level' => $term instanceof Category ? $term->level : null,
            'parentId' => $term instanceof Category ? $term->getParent()?->id : null,
            'status' => $term->getStatus(),
        ];
    }

    protected function typeProperty(): array
    {
        return ['type' => 'string', 'enum' => ['category', 'tag']];
    }
}
