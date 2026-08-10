<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

/**
 * Runtime normalizer for legacy/null gallery state. Does not rewrite DB on read.
 */
final class ProductGalleryStateNormalizer
{
    /**
     * @param  array<string, mixed>  $rawBlock
     * @param  list<int>  $usableChildIds  known generated children for this article/execution
     * @param  list<int>  $originalAlbumIds  product album / snapshot originals
     * @return array<string, mixed>
     */
    public function normalize(
        array $rawBlock,
        array $usableChildIds = [],
        array $originalAlbumIds = [],
    ): array {
        $childIds = $this->positiveIds(
            is_array($rawBlock['child_media_ids'] ?? null) ? $rawBlock['child_media_ids'] : $usableChildIds,
        );
        if ($childIds === [] && $usableChildIds !== []) {
            $childIds = $this->positiveIds($usableChildIds);
        }

        $snapshot = is_array($rawBlock['fallback_snapshot'] ?? null) ? $rawBlock['fallback_snapshot'] : [];
        if (($snapshot['media_ids'] ?? null) === null && isset($rawBlock['original_media_snapshot_ids'])) {
            $snapshot['media_ids'] = $rawBlock['original_media_snapshot_ids'];
        }
        $snapshotIds = $this->positiveIds(
            is_array($snapshot['media_ids'] ?? null) ? $snapshot['media_ids'] : $originalAlbumIds,
        );
        $snapshotUrls = array_values(array_filter(array_map(
            static fn (mixed $url): string => trim((string) $url),
            is_array($snapshot['urls'] ?? null) ? $snapshot['urls'] : [],
        ), static fn (string $url): bool => $url !== ''));

        $explicitSource = array_key_exists('gallery_source', $rawBlock)
            ? ProductGallerySource::fromLegacy($rawBlock['gallery_source'])
            : null;
        $explicitReady = array_key_exists('gallery_ready', $rawBlock)
            ? (bool) $rawBlock['gallery_ready']
            : null;

        // Infer when legacy null / pending without usable signals.
        if ($explicitSource === null || $explicitSource === ProductGallerySource::Pending) {
            if ($childIds !== []) {
                $source = ProductGallerySource::AiChildren;
                $ready = true;
                $quality = ProductGalleryQuality::Usable;
            } elseif ($snapshotIds !== [] || $originalAlbumIds !== []) {
                $source = ProductGallerySource::OriginalImages;
                $ready = true;
                $quality = ProductGalleryQuality::Fallback;
                if ($snapshotIds === []) {
                    $snapshotIds = $this->positiveIds($originalAlbumIds);
                }
            } else {
                $source = ProductGallerySource::Pending;
                $ready = false;
                $quality = ProductGalleryQuality::Fallback;
            }
        } else {
            $source = $explicitSource;
            $ready = $explicitReady ?? ($source !== ProductGallerySource::Pending);
            $quality = array_key_exists('gallery_quality', $rawBlock)
                ? ProductGalleryQuality::fromLegacy($rawBlock['gallery_quality'])
                : match ($source) {
                    ProductGallerySource::AiChildren, ProductGallerySource::ParentChildren => ProductGalleryQuality::Usable,
                    ProductGallerySource::Manual => ProductGalleryQuality::Manual,
                    ProductGallerySource::OriginalImages => ProductGalleryQuality::Fallback,
                    ProductGallerySource::Pending => ProductGalleryQuality::Fallback,
                };
        }

        if ($explicitReady === false && $source === ProductGallerySource::Pending) {
            $ready = false;
        }

        $mode = ProductGalleryGenerationMode::fromLegacy(
            $rawBlock['gallery_generation_mode'] ?? ProductGalleryGenerationMode::Sprite->value,
        );

        if (array_key_exists('gallery_quality', $rawBlock) && $rawBlock['gallery_quality'] !== null && $rawBlock['gallery_quality'] !== '') {
            $quality = ProductGalleryQuality::fromLegacy($rawBlock['gallery_quality']);
        }

        return [
            'gallery_ready' => $ready,
            'gallery_source' => $source->value,
            'gallery_generation_mode' => $mode->value,
            'gallery_quality' => $quality->value,
            'gallery_execution_id' => isset($rawBlock['gallery_execution_id'])
                ? (string) $rawBlock['gallery_execution_id']
                : null,
            'sprite_validation' => is_array($rawBlock['sprite_validation'] ?? null)
                ? $rawBlock['sprite_validation']
                : null,
            'fallback_snapshot' => [
                'media_ids' => $snapshotIds,
                'urls' => $snapshotUrls,
                'captured_at' => isset($snapshot['captured_at']) ? (string) $snapshot['captured_at'] : null,
                'origin' => isset($snapshot['origin']) ? (string) $snapshot['origin'] : null,
            ],
            'original_media_snapshot_ids' => $snapshotIds,
            'fallback_source' => isset($snapshot['origin']) ? (string) $snapshot['origin'] : null,
            'child_media_ids' => $childIds,
            'selected_media_ids' => $this->positiveIds(
                is_array($rawBlock['selected_media_ids'] ?? null) ? $rawBlock['selected_media_ids'] : [],
            ),
            'split' => is_array($rawBlock['split'] ?? null) ? $rawBlock['split'] : null,
            'history' => is_array($rawBlock['history'] ?? null) ? $rawBlock['history'] : [],
        ];
    }

    /**
     * @param  list<mixed>  $ids
     * @return list<int>
     */
    private function positiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $ids,
        ), static fn (int $id): bool => $id > 0)));
    }
}
