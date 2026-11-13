<?php

namespace App\Services;

use App\Models\MediaAsset;
use Illuminate\Validation\ValidationException;

class ContentAssets
{
    public function assertOwnedBy(array $document, int $ownerId, array $allowedIds = []): void
    {
        $allowedIds = array_map('intval', $allowedIds);

        foreach (($document['blocks'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }

            $ids = [];

            if (($block['type'] ?? null) === 'image') {
                $ids[] = $block['attrs']['asset_id'] ?? null;
            }

            if (isset($block['image_asset_id'])) {
                $ids[] = $block['image_asset_id'];
            }

            foreach ($ids as $id) {
                if ($id === null) {
                    continue;
                }

                if (! in_array((int) $id, $allowedIds, true)
                    && ! MediaAsset::whereKey($id)->where('owner_id', $ownerId)->exists()) {
                    throw ValidationException::withMessages([
                        'content' => 'Recurso de imagen no autorizado.',
                    ]);
                }
            }
        }
    }

    public function assetIds(array $document): array
    {
        $ids = [];

        foreach (($document['blocks'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'image' && isset($block['attrs']['asset_id'])) {
                $ids[] = (int) $block['attrs']['asset_id'];
            }

            if (isset($block['image_asset_id'])) {
                $ids[] = (int) $block['image_asset_id'];
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
