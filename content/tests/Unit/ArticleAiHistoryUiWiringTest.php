<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\AiPrompt\Filament\Resources\ArticleResource\Pages\ViewArticlePrompts;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryApplicationService;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryLegacyClassifier;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ArticleAiHistoryUiWiringTest extends TestCase
{
    public function test_view_article_prompts_delegates_to_application_service(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ViewArticlePrompts::class))->getFileName(),
        );

        self::assertStringContainsString('ArticleAiHistoryApplicationService', $src);
        self::assertStringContainsString('applyOutline', $src);
        self::assertStringContainsString('applyContent', $src);
        self::assertStringContainsString('bulkDeleteSelected', $src);
        self::assertStringContainsString('loadPreview', $src);
        self::assertStringNotContainsString('PromptRunnerService', $src);
        self::assertStringNotContainsString('queueArticlePipelineRerun', $src);
    }

    public function test_history_blade_has_typed_actions_and_no_page_nested_scroll_wrapper(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/view-article-prompts.blade.php'),
        );

        self::assertStringContainsString('applyOutline', $blade);
        self::assertStringContainsString('applyContent', $blade);
        self::assertStringContainsString('can_apply_outline', $blade);
        self::assertStringContainsString('can_apply_content', $blade);
        self::assertStringContainsString('bulkDeleteSelected', $blade);
        self::assertStringContainsString('filterType', $blade);
        self::assertStringContainsString('x-select', $blade);
        self::assertStringContainsString('openTwoCol', $blade);
        self::assertStringContainsString('seo-run-history-columns', $blade);
        self::assertStringContainsString('md:grid-cols-2', $blade);
        self::assertStringNotContainsString('seo-run-history-item__content', $blade);
        self::assertStringNotContainsString('x-collapse', $blade);
        self::assertStringNotContainsString('overflow-y-scroll', $blade);
        self::assertStringNotContainsString('Chạy lại quy trình', $blade);
    }

    public function test_legacy_classifier_and_application_facade_exist(): void
    {
        self::assertTrue(class_exists(ArticleAiHistoryLegacyClassifier::class));
        self::assertTrue(class_exists(ArticleAiHistoryApplicationService::class));
        self::assertTrue(method_exists(ArticleAiHistoryApplicationService::class, 'list'));
        self::assertTrue(method_exists(ArticleAiHistoryApplicationService::class, 'applyOutline'));
        self::assertTrue(method_exists(ArticleAiHistoryApplicationService::class, 'applyContent'));
        self::assertTrue(method_exists(ArticleAiHistoryApplicationService::class, 'delete'));
        self::assertTrue(method_exists(ArticleAiHistoryApplicationService::class, 'bulkDelete'));
    }
}
