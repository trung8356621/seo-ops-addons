<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Media\Services\ArticleEditorMediaAiService;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ImageProviderCapabilityResolver;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryParentChildDispatchService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ProductGalleryImageRoutingSettlementContractTest extends TestCase
{
    public function test_mode2_passes_concrete_model_not_null(): void
    {
        $method = new ReflectionMethod(ArticleEditorMediaAiService::class, 'maybeStartMode2Gallery');
        $src = $this->methodBody($method);

        self::assertStringContainsString("provider: 'gemini'", $src);
        self::assertStringContainsString('model: $resolvedModel', $src);
        self::assertStringNotContainsString('model: null', $src);
        self::assertStringContainsString('resolveProductGalleryReferenceCapability', $src);
        self::assertStringContainsString('KhÃ´ng cÃ³ model áº£nh há»— trá»£ Parent/Child trong cáº¥u hÃ¬nh hiá»‡n táº¡i.', $src);
    }

    public function test_mode2_requires_execution_id_for_processing(): void
    {
        $method = new ReflectionMethod(ArticleEditorMediaAiService::class, 'maybeStartMode2Gallery');
        $src = $this->methodBody($method);

        self::assertStringContainsString('gallery_execution_id', $src);
        self::assertStringContainsString('enforceGenerateImageSettlement', $src);
        self::assertStringContainsString('Mode 2 Parent/Child khÃ´ng cÃ³ gallery_execution_id Ä‘á»ƒ theo dÃµi.', $src);
    }

    public function test_dispatch_persists_execution_before_job(): void
    {
        $src = file_get_contents(
            (new ReflectionClass(ProductGalleryParentChildDispatchService::class))->getFileName() ?: '',
        );
        self::assertIsString($src);
        self::assertStringContainsString("'status' => 'pending'", $src);
        self::assertStringContainsString('executionId: $executionId', $src);
        self::assertStringContainsString("'execution_id' => \$executionId", $src);
    }

    public function test_settlement_rejects_processing_without_ids(): void
    {
        $service = (new ReflectionClass(ArticleEditorMediaAiService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ArticleEditorMediaAiService::class, 'enforceGenerateImageSettlement');
        $method->setAccessible(true);

        /** @var array<string, mixed> $failed */
        $failed = $method->invoke($service, [
            'status' => 'processing',
            'seo_media_id' => 0,
            'gallery_execution_id' => '',
        ]);
        self::assertSame('failed', $failed['status']);

        /** @var array<string, mixed> $withMedia */
        $withMedia = $method->invoke($service, [
            'status' => 'processing',
            'seo_media_id' => 42,
            'gallery_execution_id' => '',
        ]);
        self::assertSame('processing', $withMedia['status']);

        /** @var array<string, mixed> $withExecution */
        $withExecution = $method->invoke($service, [
            'status' => 'processing',
            'seo_media_id' => 0,
            'gallery_execution_id' => 'pgpc_abc',
        ]);
        self::assertSame('processing', $withExecution['status']);
    }

    public function test_gemini_preview_model_supports_parent_child_reference(): void
    {
        $caps = (new ImageProviderCapabilityResolver)->resolve('gemini', 'gemini-3.1-flash-image-preview');
        self::assertTrue($caps->allowsParentChild());
        self::assertTrue($caps->supportsReferenceImage);
    }

    public function test_frontend_guards_invalid_processing_and_wires_capability(): void
    {
        $modal = file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/components/GenerateImageModal.jsx',
        );
        self::assertIsString($modal);
        self::assertStringContainsString('processing && mediaId <= 0 && executionId === \'\'', $modal);
        self::assertStringContainsString('resolveProductGalleryReferenceCapability', $modal);
        self::assertStringContainsString('pollProductGalleryExecutionStatus', $modal);
        self::assertStringContainsString('setProviderSupportsReference', $modal);
        self::assertStringContainsString('setPendingExecutionId', $modal);
    }

    public function test_adapter_uses_shared_routing_not_legacy_flash_fallback(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Commerce\Services\ProductGallery\GeminiProductGalleryParentChildAiAdapter::class))->getFileName() ?: '',
        );
        self::assertStringNotContainsString("return 'gemini-2.5-flash-image';", $source);
        self::assertStringContainsString('ImageRoutingStrategy', $source);
        self::assertStringContainsString('productContext: true', $source);
        self::assertStringContainsString('KhÃ´ng cÃ³ model áº£nh há»— trá»£ Parent/Child trong cáº¥u hÃ¬nh hiá»‡n táº¡i.', $source);
    }

    private function methodBody(ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
