<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryCanaryPromptPreviewService;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryCanaryReadinessService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProductGalleryCanaryReadinessTest extends TestCase
{
    public function test_readiness_statuses_and_checklist_keys(): void
    {
        $this->assertSame('OK', ProductGalleryCanaryReadinessService::STATUS_OK);
        $this->assertSame('Thiếu', ProductGalleryCanaryReadinessService::STATUS_MISSING);
        $this->assertSame('Không hỗ trợ', ProductGalleryCanaryReadinessService::STATUS_UNSUPPORTED);

        $source = (string) file_get_contents(
            (new ReflectionClass(ProductGalleryCanaryReadinessService::class))->getFileName() ?: '',
        );
        foreach ([
            'post_type',
            'original_media',
            'mode1_binding',
            'product.gallery.plan',
            'product.gallery.parent.generate',
            'product.gallery.child.generate',
            'reference_capability',
            'feature_flag',
            'allowlist',
            'queue',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
        $this->assertStringNotContainsString('generateNativeImage', $source);
    }

    public function test_prompt_preview_strips_binary_and_uses_compile_only(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ProductGalleryCanaryPromptPreviewService::class))->getFileName() ?: '',
        );
        $this->assertStringContainsString('binary_stripped', $source);
        $this->assertStringContainsString('mode2Runtime->compile', $source);
        $this->assertStringContainsString('product.gallery.generate', $source);
        $this->assertStringNotContainsString('executeText', $source);
    }

    public function test_auto_resolve_uses_orchestrator(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ProductGalleryCanaryReadinessService::class))->getFileName() ?: '',
        );
        $this->assertStringContainsString("decide('auto'", $source);
    }
}
