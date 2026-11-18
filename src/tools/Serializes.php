<?php

namespace eventiva\mcp\tools;

use craft\elements\Asset;
use craft\elements\Entry;

trait Serializes
{
    protected function entrySummary(Entry $entry): array
    {
        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'section' => $entry->getSection()?->handle,
            'entryType' => $entry->getType()->handle,
            'site' => $entry->getSite()->handle,
            'status' => $entry->getStatus(),
            'postDate' => $entry->postDate?->format(DATE_ATOM),
            'dateUpdated' => $entry->dateUpdated?->format(DATE_ATOM),
            'uri' => $entry->uri,
            'url' => $entry->getUrl(),
            'cpEditUrl' => $entry->getCpEditUrl(),
        ];
    }

    protected function assetSummary(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'title' => $asset->title,
            'filename' => $asset->filename,
            'kind' => $asset->kind,
            'size' => $asset->size,
            'width' => $asset->width,
            'height' => $asset->height,
            'alt' => $asset->alt,
            'volume' => $asset->getVolume()->handle,
            'folderId' => $asset->folderId,
            'folderPath' => $asset->folderPath,
            'url' => $asset->getUrl(),
            'dateUpdated' => $asset->dateUpdated?->format(DATE_ATOM),
        ];
    }
}
