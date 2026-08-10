<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;

use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGallerySelectionService;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductSpriteValidator;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryArtifactRole;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGenerationMode;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryQuality;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySource;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryStateNormalizer;
use Omnichannel\Addons\Media\Support\ProductGallery\SpriteValidationResult;
use PHPUnit\Framework\TestCase;

final class ProductGalleryMode1FinalContractTest extends TestCase
{
    public function test_hard_fail_selects_original_images(): void
    {
        $validation = SpriteValidationResult::hardFail('bad', 3, ['min_canvas'], threshold: 0.8);
        $selection = (new ProductGallerySelectionService(6))->select(
            usableChildIds: [],
            rejectedChildIds: [],
            originalSnapshotIds: [10, 11],
            validation: $validation,
        );

        $this->assertTrue($selection->galleryReady);
        $this->assertSame(ProductGallerySource::OriginalImages, $selection->gallerySource);
        $this->assertSame(ProductGalleryQuality::Fallback, $selection->galleryQuality);
        $this->assertSame(ProductGalleryGenerationMode::Sprite, $selection->galleryGenerationMode);
    }

    public function test_confidence_0_79_does_not_pass_threshold(): void
    {
        $result = new SpriteValidationResult(
            valid: false,
            hardFailed: false,
            confidence: 0.79,
            threshold: 0.8,
            reason: 'soft fail',
            expectedGrid: 3,
            detectedPanels: 9,
            splitStrategy: SpriteValidationResult::STRATEGY_NONE,
        );

        $this->assertFalse($result->passesThreshold(0.8));
    }

    public function test_confidence_0_80_allows_split(): void
    {
        $result = new SpriteValidationResult(
            valid: true,
            hardFailed: false,
            confidence: 0.8,
            threshold: 0.8,
            reason: 'ok',
            expectedGrid: 3,
            detectedPanels: 9,
            splitStrategy: SpriteValidationResult::STRATEGY_FIXED_GRID,
        );

        $this->assertTrue($result->passesThreshold(0.8));
    }

    public function test_enough_usable_children_selects_ai_children(): void
    {
        $selection = (new ProductGallerySelectionService(6))->select(
            usableChildIds: [1, 2, 3, 4, 5, 6],
            rejectedChildIds: [7],
            originalSnapshotIds: [10],
            validation: new SpriteValidationResult(
                valid: true,
                hardFailed: false,
                confidence: 0.94,
                threshold: 0.8,
                reason: 'PASS',
                expectedGrid: 3,
                detectedPanels: 9,
                splitStrategy: SpriteValidationResult::STRATEGY_FIXED_GRID,
            ),
        );

        $this->assertTrue($selection->galleryReady);
        $this->assertSame(ProductGallerySource::AiChildren, $selection->gallerySource);
        $this->assertSame(ProductGalleryQuality::Perfect, $selection->galleryQuality);
        $this->assertSame([1, 2, 3, 4, 5, 6], $selection->selectedMediaIds);
    }

    public function test_not_enough_usable_children_falls_back_to_original_images(): void
    {
        $selection = (new ProductGallerySelectionService(6))->select(
            usableChildIds: [1, 2],
            rejectedChildIds: [3, 4],
            originalSnapshotIds: [20, 21],
        );

        $this->assertTrue($selection->galleryReady);
        $this->assertSame(ProductGallerySource::OriginalImages, $selection->gallerySource);
        $this->assertSame([20, 21], $selection->selectedMediaIds);
        $this->assertNotContains(1, $selection->selectedMediaIds);
    }

    public function test_no_original_and_no_children_not_ready(): void
    {
        $selection = (new ProductGallerySelectionService(1))->select(
            usableChildIds: [],
            rejectedChildIds: [],
            originalSnapshotIds: [],
        );

        $this->assertFalse($selection->galleryReady);
        $this->assertSame(ProductGallerySource::Pending, $selection->gallerySource);
        $this->assertStringContainsString('Không có ảnh sản phẩm khả dụng', $selection->reason);
    }

    public function test_normalizer_maps_legacy_original_fallback(): void
    {
        $normalized = (new ProductGalleryStateNormalizer)->normalize([
            'gallery_source' => 'original_fallback',
            'gallery_ready' => true,
        ]);

        $this->assertSame(ProductGallerySource::OriginalImages->value, $normalized['gallery_source']);
        $this->assertTrue($normalized['gallery_ready']);
    }

    public function test_normalizer_infers_ai_children_from_child_ids(): void
    {
        $normalized = (new ProductGalleryStateNormalizer)->normalize(
            [],
            usableChildIds: [5, 6],
        );

        $this->assertSame(ProductGallerySource::AiChildren->value, $normalized['gallery_source']);
        $this->assertTrue($normalized['gallery_ready']);
        $this->assertSame(ProductGalleryQuality::Usable->value, $normalized['gallery_quality']);
    }

    public function test_normalizer_infers_original_from_album(): void
    {
        $normalized = (new ProductGalleryStateNormalizer)->normalize(
            ['gallery_source' => null],
            originalAlbumIds: [9],
        );

        $this->assertSame(ProductGallerySource::OriginalImages->value, $normalized['gallery_source']);
        $this->assertTrue($normalized['gallery_ready']);
        $this->assertSame(ProductGalleryQuality::Fallback->value, $normalized['gallery_quality']);
    }

    public function test_artifact_roles_include_mode2_scaffold_values(): void
    {
        $this->assertTrue(ProductGalleryArtifactRole::isKnown(ProductGalleryArtifactRole::GENERATED_PARENT));
        $this->assertTrue(ProductGalleryArtifactRole::isKnown(ProductGalleryArtifactRole::GENERATED_CHILD_REFERENCE));
        $this->assertSame('generated_sprite', ProductGalleryArtifactRole::GENERATED_SPRITE);
    }

    public function test_ready_state_never_exposes_original_fallback_string(): void
    {
        $state = ProductGalleryReadyState::normalizeBlock([
            'gallery_source' => 'original_fallback',
            'gallery_ready' => true,
            'fallback_snapshot' => ProductGalleryReadyState::buildFallbackSnapshot(
                [['id' => 1, 'url' => 'https://x.test/a.jpg']],
                ProductGalleryReadyState::ORIGIN_GENERATE_INPUT,
            ),
        ]);

        $this->assertSame('original_images', $state['gallery_source']);
        $this->assertNotSame('original_fallback', $state['gallery_source']);
    }

    public function test_connected_input_origin_preferred_in_snapshot(): void
    {
        $snapshot = ProductGalleryReadyState::buildFallbackSnapshot(
            [['id' => 3, 'url' => 'https://x.test/in.jpg']],
            ProductGalleryReadyState::ORIGIN_GENERATE_INPUT,
        );

        $this->assertSame(ProductGalleryReadyState::ORIGIN_GENERATE_INPUT, $snapshot['origin']);
        $this->assertSame([3], $snapshot['media_ids']);
    }

    public function test_validator_threshold_default_is_0_8(): void
    {
        $this->assertSame(0.8, (new ProductSpriteValidator([]))->confidenceThreshold());
    }

    public function test_selection_history_has_debug_fields(): void
    {
        $selection = (new ProductGallerySelectionService(1))->select(
            usableChildIds: [1],
            rejectedChildIds: [],
            originalSnapshotIds: [9],
            galleryExecutionId: 'pg_test',
            historyExtra: ['sprite_media_id' => 42],
        );

        $this->assertSame('pg_test', $selection->galleryExecutionId);
        $this->assertArrayHasKey('usable_child_count', $selection->history);
        $this->assertArrayHasKey('selected_media_ids', $selection->history);
        $this->assertSame(42, $selection->history['sprite_media_id']);
    }
}
