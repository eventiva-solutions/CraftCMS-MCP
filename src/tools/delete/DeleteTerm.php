<?php

namespace eventiva\mcp\tools\delete;

use Craft;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\TermHelper;
use eventiva\mcp\tools\Writes;

class DeleteTerm extends AbstractTool
{
    use TermHelper, Writes;

    public function name(): string
    {
        return 'delete_term';
    }

    public function description(): string
    {
        return 'Deletes a category or tag (soft delete).';
    }

    public function scope(): string
    {
        return 'delete';
    }

    public function properties(): array
    {
        return ['type' => $this->typeProperty(), 'id' => ['type' => 'integer']];
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
        $this->assertCanDelete($term);

        if (!Craft::$app->getElements()->deleteElement($term)) {
            throw new \RuntimeException('Could not be deleted.');
        }
        return ['deleted' => true, 'id' => (int)$args['id'], 'title' => $term->title];
    }
}
