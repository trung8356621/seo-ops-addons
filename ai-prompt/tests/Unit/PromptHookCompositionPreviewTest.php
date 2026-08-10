<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookCompositionPreviewService;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDeterministicTemplateRenderer;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRenderedPromptCompiler;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeSettingsResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptHookPresentationService;
use PHPUnit\Framework\TestCase;

final class PromptHookCompositionPreviewTest extends TestCase
{
    private function previewService(): PromptHookCompositionPreviewService
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $catalog = new PromptHookEditorCatalog(new PromptHookRuntimeRegistry($loader));

        return new PromptHookCompositionPreviewService(
            $catalog,
            new PromptHookDeterministicTemplateRenderer,
            new PromptHookRuntimeSettingsResolver,
            new PromptHookRenderedPromptCompiler,
            null,
        );
    }

    public function test_no_hook_preview_mirrors_markdown_only(): void
    {
        $preview = $this->previewService()->preview(null, null, "# Role\nHello {{name}}");

        self::assertSame('none', $preview['content_mode']);
        self::assertFalse($preview['unused_markdown']);
        self::assertStringContainsString('Hello {{name}}', $preview['final_prompt']);
        self::assertStringNotContainsString('spec_version', $preview['final_prompt']);
        self::assertStringNotContainsString('content_mode', $preview['final_prompt']);
    }

    public function test_inline_hook_preview_uses_hook_template_not_markdown(): void
    {
        $preview = $this->previewService()->preview(
            'article.title_suggestion',
            '0.1.0',
            "# Role\nTHIS_MARKDOWN_MUST_NOT_APPEAR",
        );

        self::assertSame(PromptHookPresentationService::CONTENT_MODE_INLINE, $preview['content_mode']);
        self::assertTrue($preview['unused_markdown']);
        self::assertStringNotContainsString('THIS_MARKDOWN_MUST_NOT_APPEAR', $preview['final_prompt']);
        self::assertStringContainsString('{{keyword}}', $preview['final_prompt']);
        self::assertStringContainsString('Suggest one SEO title', $preview['final_prompt']);
        self::assertStringNotContainsString('json_object', $preview['final_prompt']);
        self::assertStringNotContainsString('settings_visible', json_encode($preview, JSON_THROW_ON_ERROR));
    }

    public function test_legacy_hook_preview_includes_prompt_markdown(): void
    {
        $markdown = "# Role\nWrite exactly 2 seeded comments.\n\n# Task\nUse format Name | Email | Content";
        $preview = $this->previewService()->preview(
            'article.comment.generate',
            '0.1.0',
            $markdown,
        );

        self::assertSame(PromptHookPresentationService::CONTENT_MODE_LEGACY_PROMPT, $preview['content_mode']);
        self::assertFalse($preview['unused_markdown']);
        self::assertStringContainsString('Write exactly 2 seeded comments', $preview['final_prompt']);
        self::assertStringContainsString('Name | Email | Content', $preview['final_prompt']);
        self::assertNotEmpty($preview['segments']);
        self::assertSame('prompt_own', $preview['segments'][0]['key']);
    }

    public function test_changing_markdown_updates_legacy_preview(): void
    {
        $service = $this->previewService();
        $a = $service->preview('article.content.translate', '0.1.0', "# Task\nTranslate to English");
        $b = $service->preview('article.content.translate', '0.1.0', "# Task\nTranslate to French");

        self::assertStringContainsString('Translate to English', $a['final_prompt']);
        self::assertStringContainsString('Translate to French', $b['final_prompt']);
        self::assertNotSame($a['final_prompt'], $b['final_prompt']);
    }

    public function test_changing_hook_updates_preview_mode(): void
    {
        $service = $this->previewService();
        $legacy = $service->preview('article.comment.generate', '0.1.0', "# Task\nHi");
        $inline = $service->preview('article.faq.generate', '0.1.0', "# Task\nHi");

        self::assertSame(PromptHookPresentationService::CONTENT_MODE_LEGACY_PROMPT, $legacy['content_mode']);
        self::assertSame(PromptHookPresentationService::CONTENT_MODE_INLINE, $inline['content_mode']);
        self::assertStringContainsString('faqs', $inline['final_prompt']);
    }

    public function test_faq_template_asks_for_faqs_object(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $definition = (new PromptHookRuntimeRegistry($loader))->get('article.faq.generate', '0.1.0');

        self::assertStringContainsString('faqs', (string) ($definition->template['system'] ?? ''));
        self::assertTrue(($definition->outputSchema->validation['json_object'] ?? false) === true);
        self::assertStringNotContainsString('JSON array', (string) ($definition->template['system'] ?? ''));
    }

    public function test_comment_presentation_does_not_hardcode_three_comments(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $catalog = new PromptHookEditorCatalog(new PromptHookRuntimeRegistry($loader));
        $view = (new PromptHookPresentationService($catalog))->forHook('article.comment.generate');
        self::assertNotNull($view);

        $blob = strtolower(json_encode($view, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('exactly 3', $blob);
        self::assertStringNotContainsString('đúng 3', $blob);
    }

    public function test_compiler_matches_inline_final_prompt_shape(): void
    {
        $preview = $this->previewService()->preview(
            'article.meta_description_suggestion',
            '0.1.0',
            '',
        );

        self::assertStringContainsString('Suggest one SEO meta description', $preview['final_prompt']);
        self::assertStringContainsString('USER:', $preview['final_prompt']);
        self::assertStringContainsString('{{title}}', $preview['final_prompt']);
        self::assertStringContainsString('{{old_description}}', $preview['final_prompt']);
    }
}
