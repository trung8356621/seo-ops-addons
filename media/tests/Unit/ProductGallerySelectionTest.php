<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;

use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGallerySelectionService;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGenerationMode;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryQuality;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySelectionResult;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySource;
use PHPUnit\Framework\TestCase;

final class ProductGallerySelectionTest extends TestCase
{
    public function test_select_ai_children_when_enough(): void
    {
        $result = (new ProductGallerySelectionService(3))->select(
            [10, 11, 12],
            [13],
            [1, 2],
        );

        $this->assertInstanceOf(ProductGallerySelectionResult::class, $result);
        $this->assertTrue($result->galleryReady);
        $this->assertSame(ProductGallerySource::AiChildren, $result->gallerySource);
        $this->assertSame([10, 11, 12], $result->selectedMediaIds);
    }

    public function test_select_original_images_when_short(): void
    {
        $result = (new ProductGallerySelectionService(4))->select(
            [10],
            [],
            [5, 6, 7],
            ProductGalleryGenerationMode::Sprite,
        );

        $this->assertSame(ProductGallerySource::OriginalImages, $result->gallerySource);
        $this->assertSame(ProductGalleryQuality::Fallback, $result->galleryQuality);
        $this->assertSame([5, 6, 7], $result->selectedMediaIds);
    }

    public function test_parent_child_source_when_mode_parent_child(): void
    {
        $result = (new ProductGallerySelectionService(2))->select(
            [100, 101],
            [],
            [1],
            ProductGalleryGenerationMode::ParentChild,
        );

        $this->assertSame(ProductGallerySource::ParentChildren, $result->gallerySource);
        $this->assertSame(ProductGalleryGenerationMode::ParentChild, $result->galleryGenerationMode);
    }

    public function test_to_array_roundtrip(): void
    {
        $result = (new ProductGallerySelectionService(1))->select([1], [], [2]);
        $round = ProductGallerySelectionResult::fromArray($result->toArray());

        $this->assertSame($result->gallerySource, $round->gallerySource);
        $this->assertSame($result->selectedMediaIds, $round->selectedMediaIds);
    }
}
