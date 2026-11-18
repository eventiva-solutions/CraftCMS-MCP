<?php

namespace eventiva\mcp\tools\delete;

use Craft;
use craft\elements\Entry;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\Writes;

class DeleteEntry extends AbstractTool
{
    use Writes;

    public function name(): string
    {
        return 'delete_entry';
    }

    public function description(): string
    {
        return 'Deletes an entry (soft delete: it can be restored in the control panel). Only delete when explicitly asked to; prefer update_entry with enabled=false.';
    }

    public function scope(): string
    {
        return 'delete';
    }

    public function properties(): array
    {
        return ['id' => ['type' => 'integer']];
    }

    public function required(): array
    {
        return ['id'];
    }

    public function run(array $args): mixed
    {
        /** @var Entry|null $entry */
        $entry = Entry::find()->id((int)$args['id'])->status(null)->one();
        if (!$entry) {
            throw new \InvalidArgumentException("Entry {$args['id']} not found.");
        }
        if ($section = $entry->getSection()) {
            $this->assertSectionAllowed($section->handle);
        }
        $this->assertCanDelete($entry);

        if (!Craft::$app->getElements()->deleteElement($entry)) {
            throw new \RuntimeException('Entry could not be deleted.');
        }
        return ['deleted' => true, 'id' => (int)$args['id'], 'title' => $entry->title];
    }
}
