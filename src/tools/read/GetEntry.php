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
            'fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only return these custom field handles (saves context). Omit for all fields; large SEO bundles are then shortened to title/description.'],
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

        $only = isset($args['fields']) && is_array($args['fields']) && $args['fields'] ? array_values($args['fields']) : null;
        $values = $entry->getSerializedFieldValues($only);

        // Without an explicit field list, shorten huge SEO bundles (e.g. SEOmatic) to the essentials.
        if ($only === null) {
            foreach ($values as $handle => $value) {
                if (is_array($value) && isset($value['metaGlobalVars']) && is_array($value['metaGlobalVars'])) {
                    $values[$handle] = [
                        '_note' => 'SEO bundle shortened. Pass fields:["' . $handle . '"] to get everything.',
                        'metaGlobalVars' => array_intersect_key($value['metaGlobalVars'], array_flip(['seoTitle', 'seoDescription', 'canonicalUrl'])),
                    ];
                }
            }
        }

        return $this->entrySummary($entry) + ['fields' => $values];
    }
}
