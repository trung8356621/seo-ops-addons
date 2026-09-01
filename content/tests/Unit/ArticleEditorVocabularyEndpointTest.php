<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorLazyPayloadController;
use Omnichannel\Addons\Content\Services\ArticleEditorVocabularyPayloadService;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

final class ArticleEditorVocabularyEndpointTest extends TestCase
{
    public function test_controller_exposes_vocabulary_endpoint(): void
    {
        $body = $this->methodBody(ArticleEditorLazyPayloadController::class, 'vocabulary');

        self::assertStringContainsString('ArticleEditorVocabularyPayloadService', $body);
        self::assertStringContainsString('forArticle', $body);
    }

    public function test_provider_registers_vocabulary_route(): void
    {
        $source = (string) file_get_contents(
            LegacyAddonPath::resolve('Providers/SeoPanelProvider.php'),
        );

        self::assertStringContainsString('editor/vocabulary', $source);
        self::assertStringContainsString('seo.articles.editor.vocabulary', $source);
    }

    public function test_edit_article_bootstrap_exposes_vocabulary_endpoint(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );

        self::assertStringContainsString("'vocabulary'", $source);
        self::assertStringContainsString('seo.articles.editor.vocabulary', $source);
    }

    public function test_vocabulary_module_registered_after_links(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/editor/modules/index.js',
        );

        self::assertStringContainsString('vocabularyModule', $source);
        self::assertMatchesRegularExpression(
            '/linksModule,\s+vocabularyModule,\s+faqModule,/s',
            $source,
        );
    }

    public function test_vocabulary_sidebar_reuses_find_article_and_filters_in_article(): void
    {
        $sidebar = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleVocabularySidebar.jsx'
        );
        $searchHelper = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/internalLinkArticleSearch.js'
        );
        $plainRange = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articlePlainTextRange.js'
        );

        self::assertStringContainsString('searchInternalLinkArticlesCached', $sidebar);
        self::assertStringContainsString("mode === 'in_article'", $sidebar);
        self::assertStringContainsString('wp-article-vocabulary-scroll', $sidebar);
        self::assertStringContainsString('wp-article-vocabulary-anchor-phrase', $sidebar);
        self::assertStringContainsString('wp-article-vocabulary-candidate', $sidebar);
        self::assertStringContainsString('insertSuggestedLink', $sidebar);
        self::assertStringContainsString('mergeCandidatesWithCurrentLink', $sidebar);
        self::assertStringContainsString('vocabulary_current_link', $sidebar);
        self::assertStringContainsString('addVocabularyItemsToDraft', $sidebar);
        self::assertStringNotContainsString('wp-article-vocabulary-project-select', $sidebar);
        self::assertStringNotContainsString('openAssignToContentProject', $sidebar);
        self::assertStringContainsString("callEditArticleLivewire('searchInternalLinkArticles'", $searchHelper);
        self::assertStringContainsString('internalLinkArticleSearchCacheKey', $searchHelper);
        self::assertStringContainsString('includeLinkedText', $plainRange);
        self::assertStringNotContainsString('vocabulary_not_in_article', $sidebar);
        self::assertStringNotContainsString('vocabulary_occurrence_label', $sidebar);
        self::assertStringNotContainsString('vocabulary_related_articles', $sidebar);
    }

    public function test_service_parses_json_and_markdown_groups(): void
    {
        $parser = new WorkflowParserService(
            new SeoPromptSettingsService,
            new SeoOverviewSettingsService,
        );
        $service = new ArticleEditorVocabularyPayloadService($parser);

        $reflection = new ReflectionClass($service);
        $jsonMethod = $reflection->getMethod('parseGroups');
        $jsonMethod->setAccessible(true);

        $fromJson = $jsonMethod->invoke($service, json_encode([
            'Nhóm A' => ['alpha', 'beta'],
            'Unigrams' => ['one'],
            'FAQ' => ['What is X?'],
            'Từ đơn' => ['x'],
        ], JSON_UNESCAPED_UNICODE));
        self::assertSame(['Nhóm A' => ['alpha', 'beta']], $fromJson);

        $fromMarkdown = $jsonMethod->invoke($service, "### Nhóm B\n- gamma\n- delta\n### Unigrams\n- z\n");
        self::assertSame(['Nhóm B' => ['gamma', 'delta']], $fromMarkdown);
    }

    public function test_service_falls_back_to_outline_vocabulary_section(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditorVocabularyPayloadService.php'
        );

        self::assertStringContainsString('vocabularyMarkdownFromOutline', $source);
        self::assertStringContainsString('ArticleOutlineResolver::META_KEY', $source);
        self::assertStringContainsString('ArticleGenerationInputResolver::VOCABULARY_START', $source);
        self::assertStringContainsString('seo_article_keywords', $source);

        $parser = new WorkflowParserService(
            new SeoPromptSettingsService,
            new SeoOverviewSettingsService,
        );
        $service = new ArticleEditorVocabularyPayloadService($parser);
        $reflection = new ReflectionClass($service);
        $outlineMethod = $reflection->getMethod('vocabularyMarkdownFromOutline');
        $outlineMethod->setAccessible(true);
        $parseMethod = $reflection->getMethod('parseGroups');
        $parseMethod->setAccessible(true);

        $outline = "[START_TASK_1_OUTLINE]\n### Skip me\n- outline only\n[END_TASK_1_OUTLINE]\n\n"
            ."[START_TASK_2_VOCABULARY]\n### Holonymy\n- Thời trang\n### Unigrams\n- x\n[END_TASK_2_VOCABULARY]";
        $section = $outlineMethod->invoke($service, $outline);
        self::assertStringContainsString('### Holonymy', $section);
        self::assertStringNotContainsString('Skip me', $section);

        $groups = $parseMethod->invoke($service, $section);
        self::assertSame(['Holonymy' => ['Thời trang']], $groups);
    }

    public function test_edit_article_exposes_vocabulary_plan_add_to_draft_method(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php'
        );
        self::assertStringContainsString('function addVocabularyItemsToDraft', $source);
        self::assertStringContainsString('PlanningDraftIntakeService', $source);
        self::assertStringContainsString('->addVocabularyPhrases(', $source);
        self::assertStringContainsString('function assignVocabularyItemsToContentProject', $source);
    }

    public function test_content_project_options_for_vocabulary_include_soft_full(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php'
        );
        self::assertStringContainsString('function contentProjectOptionsForVocabularyPlanning', $source);
        self::assertStringContainsString("whereNull('archived_at')", $source);

        $method = $this->methodBody(
            \Omnichannel\Addons\Content\Filament\Resources\ArticleResource::class,
            'contentProjectOptionsForVocabularyPlanning',
        );
        self::assertStringNotContainsString('canRegisterMoreTasks()', $method);
    }

    /**
     * @param  class-string  $class
     */
    private function methodBody(string $class, string $method): string
    {
        $ref = new ReflectionClass($class);
        $m = $ref->getMethod($method);
        $lines = explode("\n", (string) file_get_contents((string) $ref->getFileName()));

        return implode("\n", array_slice(
            $lines,
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1,
        ));
    }
}
