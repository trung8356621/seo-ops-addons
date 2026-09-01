<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorLazyPayloadController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Post Phase 4 stabilization â€” FAQ / SEO idle / links / violation actions contracts.
 * Remote-first: source asserts only.
 */
final class ArticleEditorStabilizationFaqSeoLinksTest extends TestCase
{
    private function js(string $relative): string
    {
        $path = ProjectRoot::addonsPath().'/content/resources/js/'.$relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function methodBody(string $class, string $method): string
    {
        $ref = new ReflectionClass($class);
        $fn = $ref->getMethod($method);
        $start = (int) $fn->getStartLine();
        $end = (int) $fn->getEndLine();
        $lines = file((string) $ref->getFileName());
        self::assertNotFalse($lines);

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    public function test_blade_faq_root_has_no_untloaded_placeholder(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/edit-article.blade.php'),
        );

        self::assertStringContainsString('id="seo-article-faq-root"', $blade);
        self::assertStringNotContainsString('FAQ chÆ°a táº£i', $blade);
        self::assertStringNotContainsString('Táº£i FAQ', $blade);
    }

    public function test_faq_shortcode_preview_shows_count_or_create(): void
    {
        $source = $this->js('components/FaqAccordionPreview.jsx');

        self::assertStringContainsString('faq_shortcode_count', $source);
        self::assertStringContainsString('faq_shortcode_empty', $source);
        self::assertStringContainsString('faq_shortcode_edit', $source);
        self::assertStringContainsString('faq_shortcode_create', $source);
        self::assertStringContainsString('onEditFaq', $source);
        self::assertStringContainsString('onCreateFaq', $source);
    }

    public function test_faq_sidebar_panel_lazy_when_inactive(): void
    {
        $source = $this->js('editor/modules/faq/FaqSidebarPanel.jsx');

        self::assertStringContainsString('active', $source);
        self::assertStringContainsString('editor_panel_lazy_placeholder', $source);
        self::assertFileDoesNotExist($this->js('components/ArticleEditorModuleHost.jsx'));
    }

    public function test_faqs_endpoint_contract_includes_can_generate(): void
    {
        $body = $this->methodBody(ArticleEditorLazyPayloadController::class, 'faqs');

        self::assertStringContainsString("'cached' => false", $body);
        self::assertStringContainsString("'items' => \$items", $body);
        self::assertStringContainsString("'count' => count(\$items)", $body);
        self::assertStringContainsString("'can_generate'", $body);
    }

    public function test_faqs_count_endpoint_is_light(): void
    {
        $body = $this->methodBody(ArticleEditorLazyPayloadController::class, 'faqsCount');

        self::assertStringContainsString("'count' => \$count", $body);
        self::assertStringContainsString('faqs()->count()', $body);
        self::assertStringNotContainsString('payloadForArticle', $body);
    }

    public function test_seo_live_analysis_is_local_debounced_and_has_no_php_preview(): void
    {
        $hook = $this->js('hooks/useArticleEditorSeoAnalysis.js');
        $editor = $this->js('components/SeoArticleEditor.jsx');

        self::assertStringContainsString('markSeoStale', $hook);
        self::assertStringContainsString('runLocalSeoAnalysis()', $hook);
        self::assertStringContainsString('}, 450)', $hook);
        self::assertStringContainsString("console.debug('[EditPerf] seo.local'", $hook);
        self::assertStringNotContainsString('runPhpSeoPreview', $hook);
        self::assertStringNotContainsString('previewSeoScoreViaApi', $hook);
        self::assertStringNotContainsString('previewSeoScoreViaApi', $editor);
        self::assertStringContainsString('requestAnalyze', $editor);
        self::assertStringContainsString('onAnalyzeClick: requestAnalyze', $editor);
        self::assertStringContainsString('editor_seo_analyzing', $editor);
    }

    public function test_seo_violation_action_map_exists(): void
    {
        $source = $this->js('utils/seoViolationActions.js');

        self::assertStringContainsString('SEO_VIOLATION_ACTIONS', $source);
        self::assertStringContainsString('faq_missing', $source);
        self::assertStringContainsString('open-faq-generator', $source);
        self::assertStringContainsString('featured_snippet_missing', $source);
        self::assertStringContainsString('open-featured-snippet-prompt', $source);
        self::assertStringContainsString('resolveSeoViolationAction', $source);
    }

    public function test_seo_score_panel_renders_violation_actions(): void
    {
        $source = $this->js('components/SeoScorePanel.jsx');

        self::assertStringContainsString('resolveSeoViolationAction', $source);
        self::assertStringContainsString('onViolationAction', $source);
        self::assertStringContainsString('seo-assistant-score__issue-action', $source);
    }

    public function test_featured_snippet_prompt_modal_no_auto_insert(): void
    {
        $source = $this->js('components/FeaturedSnippetPromptModal.jsx');

        self::assertStringContainsString('onConfirmInsert', $source);
        self::assertStringContainsString('featured_snippet_prompt_insert', $source);
        self::assertStringContainsString('onGenerate', $source);
    }

    public function test_existing_link_scanner_classification(): void
    {
        $source = $this->js('utils/existingLinkScanner.js');

        self::assertStringContainsString('classifyLinkHref', $source);
        self::assertStringContainsString('isSkippableLinkHref', $source);
        self::assertStringContainsString('scanExistingLinksFromBlocks', $source);
        self::assertStringContainsString("mailto:", $source);
        self::assertStringContainsString("'internal'", $source);
        self::assertStringContainsString("'external'", $source);
    }

    public function test_links_sidebar_keeps_client_existing_links_separate_from_suggestions(): void
    {
        $source = $this->js('components/ArticleLinksSidebar.jsx');

        self::assertStringContainsString("source: 'links-base'", $source);
        self::assertStringContainsString('links-base', $source);
        self::assertStringContainsString('editor/links/suggestions', $source);
        self::assertStringContainsString('links_internal_title', $source);
        self::assertStringContainsString('links_orphan_pages_title', $source);
        self::assertStringContainsString('loadLinkSuggestions', $source);
    }

    public function test_editor_debounces_existing_link_scan(): void
    {
        $source = $this->js('components/SeoArticleEditor.jsx');

        self::assertStringContainsString("id: 'existing-links-scan'", $source);
        self::assertStringContainsString('debounceMs: 750', $source);
        self::assertStringContainsString('scanExistingLinksCompat', $source);
        self::assertStringContainsString("source: 'client-document'", $source);
    }

    public function test_core_bootstrap_exposes_faq_count_and_faqs_count_endpoint(): void
    {
        $editArticle = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );

        self::assertStringContainsString("'faqCount'", $editArticle);
        self::assertStringContainsString("'faqsCount'", $editArticle);
        self::assertStringContainsString('can_generate_faq', $editArticle);

        $provider = (string) file_get_contents(
            LegacyAddonPath::resolve('Providers/SeoPanelProvider.php'),
        );
        self::assertStringContainsString('editor/faqs/count', $provider);
        self::assertStringContainsString('faqsCount', $provider);
    }
}
