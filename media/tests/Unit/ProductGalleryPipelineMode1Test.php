<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;

use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGenerationMode;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryQuality;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySource;
use Omnichannel\Addons\Media\Support\ProductGallery\SplitResult;
use Omnichannel\Addons\Media\Support\ProductGallery\SpriteValidationResult;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProductGalleryPipelineMode1Test extends TestCase
{
    public function test_pass_path_sets_ai_children_source_contract(): void
    {
        $state = ProductGalleryReadyState::normalizeBlock([
            'gallery_ready' => true,
            'gallery_source' => ProductGallerySource::AiChildren->value,
            'gallery_generation_mode' => ProductGalleryGenerationMode::Sprite->value,
            'gallery_quality' => ProductGalleryQuality::Perfect->value,
            'child_media_ids' => [101, 102, 103],
            'fallback_snapshot' => [
                'media_ids' => [1],
                'urls' => ['https://example.test/original.jpg'],
                'origin' => ProductGalleryReadyState::ORIGIN_GENERATE_INPUT,
                'captured_at' => '2026-01-01T00:00:00+00:00',
            ],
            'sprite_validation' => [
                'valid' => true,
                'hard_failed' => false,
                'confidence' => 0.94,
                'threshold' => 0.8,
                'reason' => 'PASS',
                'expected_grid' => 3,
                'detected_panels' => 9,
            ],
        ]);

        $this->assertTrue($state['gallery_ready']);
        $this->assertSame(ProductGallerySource::AiChildren->value, $state['gallery_source']);
        $this->assertSame(ProductGalleryGenerationMode::Sprite->value, $state['gallery_generation_mode']);
    }

    public function test_fail_path_sets_original_images_and_ready(): void
    {
        $state = ProductGalleryReadyState::normalizeBlock([
            'gallery_ready' => true,
            'gallery_source' => ProductGallerySource::OriginalImages->value,
            'gallery_quality' => ProductGalleryQuality::Fallback->value,
            'child_media_ids' => [],
            'fallback_snapshot' => ProductGalleryReadyState::buildFallbackSnapshot([
                ['id' => 7, 'url' => 'https://example.test/a.jpg'],
            ], ProductGalleryReadyState::ORIGIN_ALBUM_BEFORE_GENERATE),
            'sprite_validation' => SpriteValidationResult::hardFail(
                'Canvas not square',
                3,
                ['invalid_aspect_or_grid'],
            )->toArray(),
        ]);

        $this->assertTrue($state['gallery_ready']);
        $this->assertSame(ProductGallerySource::OriginalImages->value, $state['gallery_source']);
        $this->assertSame(ProductGalleryQuality::Fallback->value, $state['gallery_quality']);
    }

    public function test_split_failure_keeps_ready_with_original_images(): void
    {
        $split = SplitResult::failed('Quick Split failed', 'QUICK_SPLIT_INVALID_CANVAS');
        $ready = ProductGalleryReadyState::normalizeBlock([
            'gallery_ready' => true,
            'gallery_source' => ProductGallerySource::OriginalImages->value,
            'split' => $split->toArray(),
            'fallback_snapshot' => ProductGalleryReadyState::buildFallbackSnapshot([
                ['id' => 5, 'url' => 'https://example.test/o.jpg'],
            ], ProductGalleryReadyState::ORIGIN_GENERATE_INPUT),
        ]);

        $this->assertTrue($ready['gallery_ready']);
        $this->assertSame(ProductGallerySource::OriginalImages->value, $ready['gallery_source']);
    }

    public function test_pipeline_service_exposes_mode1_methods(): void
    {
        $ref = new ReflectionClass(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPipelineService::class);
        $this->assertTrue($ref->hasMethod('runAfterSpriteSaved'));
        $this->assertTrue($ref->hasMethod('applyManualSplitRetry'));
    }

    public function test_manual_split_retry_updates_source_contract(): void
    {
        $afterRetry = ProductGalleryReadyState::normalizeBlock([
            'gallery_ready' => true,
            'gallery_source' => ProductGallerySource::AiChildren->value,
            'child_media_ids' => [201, 202],
            'split' => [
                'success' => true,
                'usable_child_ids' => [201, 202],
                'reason' => 'manual_split_retry',
            ],
        ]);

        $this->assertSame(ProductGallerySource::AiChildren->value, $afterRetry['gallery_source']);
    }
}
