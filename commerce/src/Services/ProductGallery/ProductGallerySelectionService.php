<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGenerationMode;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryQuality;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySelectionResult;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySource;
use Omnichannel\Addons\Media\Support\ProductGallery\SpriteValidationResult;

/**
 * Single selection authority for Product Gallery Mode 1 (+ Mode 2 reuse).
 * Policy: enough usable AI children → ai_children; else original_images; else pending.
 * No hybrid mix in Phase A.
 */
final class ProductGallerySelectionService
{
    private readonly int $minimumRequiredImages;

    public function __construct(?int $minimumRequiredImages = null)
    {
        if ($minimumRequiredImages !== null) {
            $this->minimumRequiredImages = max(1, $minimumRequiredImages);

            return;
        }

        try {
            $min = (int) config('seo-content-ai.product_gallery.minimum_required_images', 1);
        } catch (\Throwable) {
            $min = 1;
        }

        $this->minimumRequiredImages = max(1, $min);
    }

    public static function fromConfig(): self
    {
        return new self(null);
    }

    /**
     * @param  list<int>  $usableChildIds
     * @param  list<int>  $rejectedChildIds
     * @param  list<int>  $originalSnapshotIds
     * @param  array<string, mixed>  $historyExtra
     */
    public function select(
        array $usableChildIds,
        array $rejectedChildIds,
        array $originalSnapshotIds,
        ProductGalleryGenerationMode $mode = ProductGalleryGenerationMode::Sprite,
        ?SpriteValidationResult $validation = null,
        ?string $galleryExecutionId = null,
        string $splitStrategy = SpriteValidationResult::STRATEGY_NONE,
        array $historyExtra = [],
    ): ProductGallerySelectionResult {
        $usableChildIds = $this->positiveIds($usableChildIds);
        $rejectedChildIds = $this->positiveIds($rejectedChildIds);
        $originalSnapshotIds = $this->positiveIds($originalSnapshotIds);
        $executionId = $galleryExecutionId ?: $this->newExecutionId();
        $minimumRequired = $this->minimumForMode($mode);

        $confidence = $validation?->confidence;
        $threshold = $validation?->threshold ?? 0.8;
        $qualityFromConfidence = static function (?float $c, float $t): ProductGalleryQuality {
            if ($c === null) {
                return ProductGalleryQuality::Usable;
            }
            if ($c >= max($t, 0.9)) {
                return ProductGalleryQuality::Perfect;
            }

            return ProductGalleryQuality::Usable;
        };

        if (count($usableChildIds) >= $minimumRequired) {
            $quality = $mode === ProductGalleryGenerationMode::ParentChild
                ? $qualityFromConfidence($confidence, $threshold)
                : $qualityFromConfidence($confidence, $threshold);
            $source = $mode === ProductGalleryGenerationMode::ParentChild
                ? ProductGallerySource::ParentChildren
                : ProductGallerySource::AiChildren;

            return new ProductGallerySelectionResult(
                galleryReady: true,
                gallerySource: $source,
                galleryGenerationMode: $mode,
                galleryQuality: $quality,
                selectedMediaIds: $usableChildIds,
                rejectedMediaIds: $rejectedChildIds,
                reason: sprintf('Usable AI children: %d (min %d).', count($usableChildIds), $minimumRequired),
                reasonCodes: ['ai_children_ok'],
                galleryExecutionId: $executionId,
                history: array_merge([
                    'usable_child_count' => count($usableChildIds),
                    'rejected_child_count' => count($rejectedChildIds),
                    'original_media_snapshot_ids' => $originalSnapshotIds,
                    'selected_media_ids' => $usableChildIds,
                    'validator_confidence' => $confidence,
                    'validator_threshold' => $threshold,
                    'split_strategy' => $splitStrategy,
                ], $historyExtra),
            );
        }

        if ($originalSnapshotIds !== []) {
            return new ProductGallerySelectionResult(
                galleryReady: true,
                gallerySource: ProductGallerySource::OriginalImages,
                galleryGenerationMode: $mode,
                galleryQuality: ProductGalleryQuality::Fallback,
                selectedMediaIds: $originalSnapshotIds,
                rejectedMediaIds: array_values(array_unique(array_merge($rejectedChildIds, $usableChildIds))),
                reason: count($usableChildIds) > 0
                    ? sprintf('Not enough usable AI children (%d < %d); using original images.', count($usableChildIds), $minimumRequired)
                    : 'AI sprite unsafe or split failed; using original product images.',
                reasonCodes: ['original_images_fallback'],
                galleryExecutionId: $executionId,
                history: array_merge([
                    'usable_child_count' => count($usableChildIds),
                    'rejected_child_count' => count($rejectedChildIds),
                    'original_media_snapshot_ids' => $originalSnapshotIds,
                    'selected_media_ids' => $originalSnapshotIds,
                    'fallback_reason' => 'insufficient_usable_children_or_validation_fail',
                    'validator_confidence' => $confidence,
                    'validator_threshold' => $threshold,
                    'split_strategy' => $splitStrategy,
                ], $historyExtra),
            );
        }

        return new ProductGallerySelectionResult(
            galleryReady: false,
            gallerySource: ProductGallerySource::Pending,
            galleryGenerationMode: $mode,
            galleryQuality: ProductGalleryQuality::Fallback,
            selectedMediaIds: [],
            rejectedMediaIds: array_values(array_unique(array_merge($rejectedChildIds, $usableChildIds))),
            reason: 'Không có ảnh sản phẩm khả dụng để tạo gallery.',
            reasonCodes: ['no_usable_gallery_source'],
            galleryExecutionId: $executionId,
            history: array_merge([
                'usable_child_count' => count($usableChildIds),
                'rejected_child_count' => count($rejectedChildIds),
                'original_media_snapshot_ids' => [],
                'selected_media_ids' => [],
                'fallback_reason' => 'no_original_and_no_children',
                'validator_confidence' => $confidence,
                'validator_threshold' => $threshold,
            ], $historyExtra),
        );
    }

    private function minimumForMode(ProductGalleryGenerationMode $mode): int
    {
        if ($mode !== ProductGalleryGenerationMode::ParentChild) {
            return $this->minimumRequiredImages;
        }

        try {
            $min = (int) config(
                'seo-content-ai.product_gallery.parent_child.minimum_required_images',
                $this->minimumRequiredImages,
            );
        } catch (\Throwable) {
            $min = $this->minimumRequiredImages;
        }

        return max(1, $min);
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

    private function newExecutionId(): string
    {
        return 'pg_'.bin2hex(random_bytes(8));
    }
}
