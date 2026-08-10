<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Commerce\Services\ProductGallery\GeminiProductGalleryParentChildAiAdapter;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPromptHookRuntime;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryReferenceImageResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class GeminiProductGalleryPromptIntegrationTest extends TestCase
{
    public function test_adapter_fallback_brief_disabled(): void
    {
        $this->assertFalse(GeminiProductGalleryParentChildAiAdapter::FALLBACK_BRIEF_ENABLED);
    }

    public function test_adapter_source_has_no_hardcoded_fallback_brief(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(GeminiProductGalleryParentChildAiAdapter::class))->getFileName() ?: '',
        );
        $this->assertStringNotContainsString('Generate exactly one product photo.', $source);
        $this->assertStringNotContainsString('hook_compile_fallback', $source);
        $this->assertStringContainsString('ProductGalleryPromptHookRuntime', $source);
        $this->assertStringContainsString('compileImageHookPrompt', $source);
        $this->assertStringContainsString('prompt_hook_binding_missing', $source);
        $this->assertStringContainsString('prompt_variable_missing', $source);
    }

    public function test_adapter_uses_runtime_compile_not_legacy_execution_service(): void
    {
        $ctor = new ReflectionMethod(GeminiProductGalleryParentChildAiAdapter::class, '__construct');
        $types = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType) {
                $types[] = $type->getName();
            }
        }
        $this->assertContains(ProductGalleryPromptHookRuntime::class, $types);
        $this->assertNotContains(
            \Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookExecutionService::class,
            $types,
        );
    }

    public function test_child_path_sends_parent_inline_data(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(GeminiProductGalleryParentChildAiAdapter::class))->getFileName() ?: '',
        );
        $this->assertStringContainsString('toGeminiInlinePart()', $source);
        $this->assertStringContainsString('generateNativeImageWithReferences', $source);
        $this->assertStringContainsString('resolveFromMedia($parent', $source);
    }

    public function test_reference_resolver_class_exists(): void
    {
        $this->assertTrue(class_exists(ProductGalleryReferenceImageResolver::class));
    }

    public function test_runtime_compile_path_uses_renderer_and_compiler(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ProductGalleryPromptHookRuntime::class))->getFileName() ?: '',
        );
        $this->assertStringContainsString('PromptHookDeterministicTemplateRenderer', $source);
        $this->assertStringContainsString('PromptHookRenderedPromptCompiler', $source);
        $this->assertStringContainsString('PromptHookExplicitBindingExecutor', $source);
        $this->assertStringContainsString('resolveSettingsHook', $source);
    }

    public function test_canary_dry_run_invokes_prompt_doctor(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/src/Console/ProductGalleryParentChildCanaryCommand.php',
        );
        $this->assertStringContainsString('ProductGalleryPromptsDoctorService', $source);
        $this->assertStringContainsString('product_title', $source);
    }
}
