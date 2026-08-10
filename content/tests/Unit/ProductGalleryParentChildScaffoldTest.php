<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ImageProviderCapabilityResolver;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryGenerationModeResolver;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPlanParser;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGallerySelectionService;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGallerySerialChildLoop;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGenerationMode;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGlobalContext;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryShotDefinition;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySource;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProductGalleryParentChildScaffoldTest extends TestCase
{
    public function test_hooks_json_files_exist(): void
    {
        $dir = ProjectRoot::addonsPath().'/ai-prompt'.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'prompt-hooks'.DIRECTORY_SEPARATOR.'v01';
        foreach ([
            'product.gallery.plan@0.1.0.json',
            'product.gallery.parent.generate@0.1.0.json',
            'product.gallery.child.generate@0.1.0.json',
        ] as $file) {
            $this->assertFileExists($dir.DIRECTORY_SEPARATOR.$file);
        }
    }

    public function test_migration_file_exists(): void
    {
        $path = ProjectRoot::addonsPath().'/ai-prompt'.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'
            .DIRECTORY_SEPARATOR.'2026_07_26_220000_create_product_gallery_parent_child_executions_tables.php';
        $this->assertFileExists($path);
    }

    public function test_coordinator_and_validators_exist(): void
    {
        $this->assertTrue(class_exists(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryParentChildCoordinator::class));
        $this->assertTrue(class_exists(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryReferenceChildValidator::class));
        $this->assertTrue(interface_exists(\Omnichannel\Addons\Seo\Contracts\ProductGalleryParentChildAiPort::class));

        $caps = (new ImageProviderCapabilityResolver)->resolve('google', 'gemini-2.5-flash-image');
        $mode = (new ProductGalleryGenerationModeResolver)->resolve('auto', $caps);
        $this->assertTrue($caps->allowsParentChild());
        $this->assertSame(ProductGalleryGenerationMode::ParentChild, $mode->resolved);

        $plan = (new ProductGalleryPlanParser(['1:1'], 4))->parse(
            '{"shots":[{"slot":1,"shot_key":"front","label":"A","priority":"required","aspect_ratio":"1:1","instruction":"front view only"}]}',
            4,
        );
        $this->assertTrue($plan['ok']);
    }

    public function test_selection_reused_for_parent_children(): void
    {
        $result = (new ProductGallerySelectionService(2))->select(
            [10, 11],
            [],
            [1],
            ProductGalleryGenerationMode::ParentChild,
        );

        $this->assertSame(ProductGallerySource::ParentChildren, $result->gallerySource);
        $this->assertSame(ProductGalleryGenerationMode::ParentChild, $result->galleryGenerationMode);
    }

    public function test_global_context_immutable_with_parent(): void
    {
        $base = ProductGalleryGlobalContext::fromArray([
            'execution_id' => 'e1',
            'article_id' => 9,
            'product_identity' => 'bag',
            'title' => 'Tote',
            'original_media_ids' => [1, 2],
            'parent_media_id' => null,
            'identity_source' => ProductGalleryGlobalContext::IDENTITY_METADATA,
        ]);
        $next = $base->withParentMediaId(55);

        $this->assertNull($base->parentMediaId);
        $this->assertSame(55, $next->parentMediaId);
        $this->assertSame(ProductGalleryGlobalContext::IDENTITY_COMBINED, $next->identitySource);
        $this->assertSame([1, 2], $base->originalMediaIds);
        $this->assertSame([1, 2], $next->originalMediaIds);
    }

    public function test_serial_child_continues_after_failure(): void
    {
        $shots = [
            new ProductGalleryShotDefinition(1, 'front', 'Front', 'required', '1:1', 'front view'),
            new ProductGalleryShotDefinition(2, 'side', 'Side', 'required', '1:1', 'side view'),
            new ProductGalleryShotDefinition(3, 'back', 'Back', 'required', '1:1', 'back view'),
        ];

        $calls = [];
        $result = (new ProductGallerySerialChildLoop)->run(
            $shots,
            static function (ProductGalleryShotDefinition $shot, int $attempt) use (&$calls): bool {
                $calls[] = $shot->slot.':'.$attempt;
                if ($shot->slot === 1) {
                    return false;
                }

                return $attempt === 1;
            },
            retryCount: 1,
        );

        $this->assertSame([2, 3], $result['success_slots']);
        $this->assertSame([1], $result['failed_slots']);
        $this->assertContains('1:1', $calls);
        $this->assertContains('1:2', $calls);
        $this->assertContains('2:1', $calls);
        // Serial order: finish slot 1 attempts before slot 2.
        $this->assertLessThan(
            array_search('2:1', $calls, true),
            array_search('1:2', $calls, true),
        );
    }

    public function test_artifact_roles_mode2(): void
    {
        $this->assertSame('generated_parent', \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryArtifactRole::GENERATED_PARENT);
        $this->assertSame('generated_child_reference', \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryArtifactRole::GENERATED_CHILD_REFERENCE);
    }

    public function test_mode1_classes_still_present(): void
    {
        $this->assertTrue(class_exists(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPipelineService::class));
        $ref = new ReflectionClass(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryParentChildCoordinator::class);
        $this->assertTrue($ref->hasMethod('run'));
        $this->assertTrue($ref->hasMethod('retryChild'));
    }
}
