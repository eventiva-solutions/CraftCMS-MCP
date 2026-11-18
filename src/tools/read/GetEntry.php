<?php

namespace eventiva\mcp\tools\read;

use Craft;
use craft\elements\Entry;
use eventiva\mcp\tools\AbstractTool;
use eventiva\mcp\tools\Serializes;

class GetEntry extends AbstractTool
{
    use Serializes;

    public function name(): string
    {
        return 'get_entry';
    }

    public function description(): string
    {
        return 'Returns one entry including all custom field values (serialized: asset/relation fields as ID lists, rich text as HTML, Matrix as block arrays).';
    }

    public function properties(): array
    {
        return [
            'id' => ['type' => 'integer', 'description' => 'Entry ID.'],
            'site' => ['type' => 'string', 'description' => 'Site handle (default: primary site).'],
        ];
    }

    public function required(): array
    {
        return ['id'];
    }

    public function run(array $args): mixed
    {
        $q = Entry::find()->id((int)$args['id'])->status(null);
        if (!empty($args['site'])) {
            $q->site($args['site']);
        }
        /** @var Entry|null $entry */
        $entry = $q->one();

        if (!$entry) {
            throw new \InvalidArgumentException("Entry {$args['id']} not found.");
        }
        if ($section = $entry->getSection()) {
            $this->assertSectionAllowed($section->handle);
        }
        if (!Craft::$app->getElements()->canView($entry, $this->user())) {
            throw new \RuntimeException('The token user may not view this entry.');
        }

        return $this->entrySummary($entry) + [
            'fields' => $entry->getSerializedFieldValues(),
        ];
    }
}
