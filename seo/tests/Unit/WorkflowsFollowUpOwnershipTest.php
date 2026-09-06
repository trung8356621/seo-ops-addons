<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsWorkflows;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class WorkflowsFollowUpOwnershipTest extends TestCase
{
    public function test_post_review_and_rewrite_not_on_workflows_form(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeoSettingsWorkflows::class))->getFileName() ?: '',
        );

        self::assertStringNotContainsString(
            'KEY_POST_REVIEW,',
            $source,
        );
        self::assertStringNotContainsString(
            "taskSelect(\n                            SeoCreateArticleSettingsService::KEY_POST_REVIEW",
            $source,
        );
        self::assertStringNotContainsString(
            'KEY_POST_REVIEW => $data',
            $source,
        );
        self::assertStringContainsString(
            'KEY_POST_REVIEW => $settings->getSettings()',
            $source,
        );
        self::assertStringContainsString(
            'KEY_REWRITE_ARTICLE => $settings->getSettings()',
            $source,
        );
        self::assertStringContainsString('KEY_PUBLISH_ARTICLE', $source);
    }

    public function test_settings_visible_outline_vocab_not_combined_outline(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $catalog = new PromptHookEditorCatalog(new PromptHookRuntimeRegistry($loader));
        $keys = array_column($catalog->settingsVisibleHooks(), 'hook_key');

        self::assertContains('article.outline.structure.generate', $keys);
        self::assertContains('article.vocabulary.generate', $keys);
        self::assertContains('article.comment.generate', $keys);
        self::assertContains('article.content.generate', $keys);
        self::assertContains('article.content.improve', $keys);
        self::assertNotContains('article.outline.generate', $keys);
        self::assertNotContains('article.content.rewrite', $keys);
    }

    public function test_legacy_outline_generate_remains_loadable_but_hidden(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $def = (new PromptHookRuntimeRegistry($loader))->get('article.outline.generate', '0.1.0');
        self::assertNotNull($def);
        self::assertFalse($def->settingsVisible);

        $path = PromptHookDefinitionLoader::defaultV01Directory()
            .DIRECTORY_SEPARATOR.'article.outline.generate@0.1.0.json';
        $spec = json_decode((string) file_get_contents($path), true);
        self::assertTrue((bool) ($spec['enabled'] ?? false));
        self::assertFalse((bool) ($spec['settings_visible'] ?? true));
    }

    public function test_normalize_source_empty_is_none_not_workflow(): void
    {
        $method = new ReflectionMethod(SeoCreateArticleSettingsService::class, 'normalizeSource');
        $method->setAccessible(true);
        $service = new SeoCreateArticleSettingsService;

        self::assertSame(
            SeoCreateArticleSettingsService::SOURCE_NONE,
            $method->invoke($service, null, null, null),
        );
        self::assertSame(
            SeoCreateArticleSettingsService::SOURCE_NONE,
            $method->invoke($service, '', null, null),
        );
        self::assertSame(
            SeoCreateArticleSettingsService::SOURCE_NONE,
            $method->invoke($service, 'none', null, null),
        );
        self::assertSame(
            SeoCreateArticleSettingsService::SOURCE_WORKFLOW,
            $method->invoke($service, 'workflow', null, null),
        );
        self::assertSame(
            SeoCreateArticleSettingsService::SOURCE_PROMPT,
            $method->invoke($service, null, null, 12),
        );
        self::assertSame(
            SeoCreateArticleSettingsService::SOURCE_WORKFLOW,
            $method->invoke($service, null, 5, null),
        );
    }

    public function test_optional_media_ui_exposes_none_prompt_workflow(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeoSettingsWorkflows::class))->getFileName() ?: '',
        );

        self::assertStringContainsString('SOURCE_NONE', $source);
        self::assertStringContainsString('source_none', $source);
    }

    public function test_merge_preserves_legacy_post_review_and_rewrite_via_save_contract(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeoSettingsWorkflows::class))->getFileName() ?: '',
        );

        self::assertStringContainsString(
            'KEY_POST_REVIEW => $settings->getSettings()[SeoCreateArticleSettingsService::KEY_POST_REVIEW]',
            $source,
        );
        self::assertStringContainsString(
            'KEY_REWRITE_ARTICLE => $settings->getSettings()[SeoCreateArticleSettingsService::KEY_REWRITE_ARTICLE]',
            $source,
        );
    }

    public function test_roundtrip_merge_keeps_system_and_user_bindings_with_video_none_semantics(): void
    {
        $service = new SeoCreateArticleSettingsService;
        $existing = [
            'article.faq.generate' => 70,
            'article.comment.generate' => 80,
            'article.featured_image.generate' => 123,
            'product.gallery.generate' => 45,
            'article.outline.generate' => 9,
        ];

        $after = $service->mergePreservingNonUserEditableBindings(
            [
                'article.faq.generate' => 71,
                'article.comment.generate' => 80,
            ],
            $existing,
        );

        self::assertSame(71, $after['article.faq.generate']);
        self::assertSame(80, $after['article.comment.generate']);
        self::assertSame(123, $after['article.featured_image.generate']);
        self::assertSame(45, $after['product.gallery.generate']);
        self::assertSame(9, $after['article.outline.generate']);
    }

    public function test_quick_review_uses_comment_prompt_service(): void
    {
        $quick = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Services\ArticleQuickPostReviewService::class))->getFileName() ?: '',
        );
        $gen = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewGenerateService::class))->getFileName() ?: '',
        );

        self::assertStringContainsString('ProductReviewGenerateService', $quick);
        self::assertStringNotContainsString('getPostReviewTaskId', $quick);
        self::assertStringNotContainsString('TaskWorkflowTestRunner', $quick);
        self::assertStringContainsString('article.comment.generate', $gen);
        self::assertStringContainsString('ProductReviewCreationPolicy', $gen);
        self::assertStringContainsString('storeLocalFromAiOutput', $gen);
    }

    public function test_heading_regen_does_not_call_outline_generate_hook(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Services\ArticleHeadingAiGenerateService::class))->getFileName() ?: '',
        );

        self::assertStringNotContainsString("hookKey: 'article.outline.generate'", $source);
        self::assertStringNotContainsString('PromptHookCallerBridge', $source);
        self::assertStringContainsString('getOutlineHeadingRegeneratorPromptId', $source);
    }

    public function test_template_batch_creator_marked_compatibility_debt(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewLocalBatchCreator::class))->getFileName() ?: '',
        );

        self::assertStringContainsString('COMPATIBILITY DEBT', $source);
        self::assertStringContainsString('CONTENT_TEMPLATES', $source);
    }
}
