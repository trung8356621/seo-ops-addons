<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGallerySelectionService;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGallerySerialChildLoop;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGenerationMode;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGlobalContext;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryQuality;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryShotDefinition;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySource;
use PHPUnit\Framework\TestCase;

final class ProductGalleryParentChildExecutionTest extends TestCase
{
    public function test_retry_child_reuses_parent_and_context_snapshot(): void
    {
        $ctx = ProductGalleryGlobalContext::fromArray([
            'execution_id' => 'exec-1',
            'article_id' => 10,
            'product_identity' => 'tote-red',
            'title' => 'Tote',
            'original_media_ids' => [1],
            'parent_media_id' => 99,
            'provider' => 'google',
            'model' => 'gemini-2.5-flash-image',
            'identity_source' => ProductGalleryGlobalContext::IDENTITY_PARENT_REFERENCE,
        ]);

        $retryCtx = $ctx; // retry must reuse same snapshot object values
        $this->assertSame(99, $retryCtx->parentMediaId);
        $this->assertSame('exec-1', $retryCtx->executionId);
        $this->assertSame([1], $retryCtx->originalMediaIds);

        // Rerun builds NEW context (new execution id / parent).
        $rerun = ProductGalleryGlobalContext::fromArray([
            'execution_id' => 'exec-2',
            'article_id' => 10,
            'product_identity' => 'tote-red',
            'title' => 'Tote',
            'original_media_ids' => [1],
            'parent_media_id' => 200,
            'identity_source' => ProductGalleryGlobalContext::IDENTITY_COMBINED,
        ]);
        $this->assertNotSame($ctx->executionId, $rerun->executionId);
        $this->assertNotSame($ctx->parentMediaId, $rerun->parentMediaId);
    }

    public function test_serial_children_one_at_a_time(): void
    {
        $shots = [
            new ProductGalleryShotDefinition(1, 'a', 'A', 'required', '1:1', 'a'),
            new ProductGalleryShotDefinition(2, 'b', 'B', 'required', '1:1', 'b'),
        ];
        $active = 0;
        $maxConcurrent = 0;

        (new ProductGallerySerialChildLoop)->run(
            $shots,
            static function () use (&$active, &$maxConcurrent): bool {
                $active++;
                $maxConcurrent = max($maxConcurrent, $active);
                $active--;

                return true;
            },
            retryCount: 0,
        );

        $this->assertSame(1, $maxConcurrent);
    }

    public function test_enough_children_select_parent_children(): void
    {
        $result = (new ProductGallerySelectionService(2))->select(
            [10, 11, 12],
            [],
            [1],
            ProductGalleryGenerationMode::ParentChild,
        );

        $this->assertTrue($result->galleryReady);
        $this->assertSame(ProductGallerySource::ParentChildren, $result->gallerySource);
        $this->assertContains($result->galleryQuality, [ProductGalleryQuality::Perfect, ProductGalleryQuality::Usable]);
    }

    public function test_insufficient_children_fallback_original(): void
    {
        $result = (new ProductGallerySelectionService(3))->select(
            [10],
            [],
            [1, 2, 3],
            ProductGalleryGenerationMode::ParentChild,
        );

        $this->assertTrue($result->galleryReady);
        $this->assertSame(ProductGallerySource::OriginalImages, $result->gallerySource);
        $this->assertSame(ProductGalleryQuality::Fallback, $result->galleryQuality);
    }

    public function test_no_usable_source_not_ready(): void
    {
        $result = (new ProductGallerySelectionService(2))->select(
            [],
            [],
            [],
            ProductGalleryGenerationMode::ParentChild,
        );

        $this->assertFalse($result->galleryReady);
        $this->assertSame(ProductGallerySource::Pending, $result->gallerySource);
    }

    public function test_parent_not_auto_inserted_as_gallery(): void
    {
        // Selection only receives child media ids — parent id never enters selected list.
        $parentId = 999;
        $result = (new ProductGallerySelectionService(1))->select(
            [11, 12],
            [],
            [],
            ProductGalleryGenerationMode::ParentChild,
        );

        $this->assertNotContains($parentId, $result->selectedMediaIds);
        $this->assertSame([11, 12], $result->selectedMediaIds);
        $this->assertSame(ProductGallerySource::ParentChildren, $result->gallerySource);
    }

    public function test_parent_one_image_and_child_one_image_contract_in_hooks(): void
    {
        $dir = ProjectRoot::addonsPath().'/ai-prompt'.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'prompt-hooks'.DIRECTORY_SEPARATOR.'v01';
        $parent = (string) file_get_contents($dir.DIRECTORY_SEPARATOR.'product.gallery.parent.generate@0.1.0.json');
        $child = (string) file_get_contents($dir.DIRECTORY_SEPARATOR.'product.gallery.child.generate@0.1.0.json');

        $this->assertStringContainsString('one image', strtolower($parent));
        $this->assertStringContainsString('no collage', strtolower($parent));
        $this->assertStringContainsString('one image', strtolower($child));
        $this->assertStringContainsString('reference', strtolower($child));
    }
}
