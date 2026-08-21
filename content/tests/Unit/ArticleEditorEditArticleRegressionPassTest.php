<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleEditorLinksPayloadService;
use Omnichannel\Addons\Content\Services\ArticleEditorMainDomainSuggestionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

final class ArticleEditorEditArticleRegressionPassTest extends TestCase
{
    public function test_manual_analyze_is_immediate_local_snapshot_without_php_preview(): void
    {
        $hook = $this->js('hooks/useArticleEditorSeoAnalysis.js');
        $request = $this->methodLike($hook, 'const requestAnalyze = useCallback');

        self::assertStringContainsString('runLocalSeoAnalysis({ force: true })', $request);
        self::assertStringContainsString('requestAnimationFrame', $request);
        self::assertStringContainsString('htmlFromEditorsOrBlocks', $hook);
        self::assertStringContainsString('createCurrentDraftAnalysisSnapshot', $hook);
        self::assertStringNotContainsString('previewSeoScoreViaApi', $hook);
        self::assertStringNotContainsString('/seo-score/preview', $hook);
        self::assertStringNotContainsString('seo-summary', $hook);
        self::assertStringContainsString('}, 450)', $hook);
        self::assertStringContainsString('useState(false)', $hook);
        self::assertStringContainsString('seoAnalysisReady', $hook);
        self::assertStringContainsString('if (!seoAnalysisReadyRef.current)', $hook);

        $panel = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/js/components/SeoScorePanel.jsx',
        );
        self::assertStringContainsString('onClick={onAnalyzeClick}', $panel);
        self::assertStringContainsString('editor_seo_update_score', $panel);
        self::assertStringContainsString('editor_seo_unanalyzed', $panel);
        self::assertStringContainsString('ready = false', $panel);
        self::assertStringContainsString("t('editor_seo_stale')", $panel);
        self::assertStringNotContainsString('<p className="seo-assistant-score__stale-hint">{t(\'editor_seo_stale\')}</p>', $panel);

        $readiness = $this->js('utils/seoAnalysisReadiness.js');
        self::assertStringContainsString('isCachedSeoAnalysisValid', $readiness);
        self::assertStringContainsString('isCompletedSeoAnalysis', $readiness);
    }

    public function test_save_ack_does_not_replace_live_seo_or_reviews(): void
    {
        $api = $this->js('utils/articleEditorApi.js');
        $ack = $this->methodLike($api, 'export function applyArticleEditorSavePatch');
        self::assertStringNotContainsString('seo-editor-analyze-result', $ack);
        self::assertStringContainsString('reloadAfterSuccess === true', $api);

        $bridge = $this->js('hooks/useArticleEditorExternalEventsBridge.js');
        $handler = $this->methodLike($bridge, 'const handleServerAnalyzeResult = (event)');
        self::assertStringContainsString('setSavedSeoScore(score)', $handler);
        self::assertStringNotContainsString('applySeoAnalysisResult(result, \'saved\')', $handler);

        $core = $this->js('hooks/useArticleEditorCoreState.js');
        self::assertStringContainsString('fetchProductReviewStatus', $core);
        self::assertStringContainsString('setReviewCount(remote + pending)', $core);
        self::assertStringNotContainsString('setVirtualReviews([])', $core);
        self::assertStringNotContainsString('previewSeoScoreViaApi', $core);
    }

    public function test_reviews_count_is_independent_and_zero_only_when_loaded(): void
    {
        $core = $this->js('hooks/useArticleEditorCoreState.js');
        self::assertStringContainsString('reviewCount !== null', $core);
        self::assertStringContainsString('window.setTimeout(() => void loadCount(), 7000)', $core);
        self::assertStringContainsString('wordpress-product-reviews', $this->js('utils/articleEditorApi.js'));

        $nav = $this->js('editor/host/EditorSidebarNavigation.jsx');
        self::assertStringContainsString("value === false", $nav);
        self::assertStringContainsString("return chipId === 'reviews' ? 0 : null;", $nav);
        self::assertStringContainsString("typeof value === 'number'", $nav);

        $host = $this->js('components/SeoArticleEditor.jsx');
        self::assertStringContainsString('reviewsBadge: showReviewsTab && isProductPost && reviewCount !== null', $host);
        self::assertStringContainsString('loaded: reviewsLoaded', $host);
        self::assertStringContainsString("lazy(() => import('@media-addon/components/GenerateImageModal.jsx'))", $host);
        self::assertStringContainsString('LazySharedMediaPicker', $host);
        self::assertStringNotContainsString('loading: seoSummaryLoading', $host);
    }

    public function test_main_domain_suggestions_use_existing_catalog_and_are_lazy(): void
    {
        $service = (string) file_get_contents(
            (new ReflectionClass(ArticleEditorMainDomainSuggestionService::class))->getFileName(),
        );
        self::assertStringContainsString('SeoMainDomainService', $service);
        self::assertStringContainsString('site-sync.link-catalog', $service);
        self::assertStringContainsString("relationship = (int) \$article->site_id === \$mainSiteId ? 'internal' : 'external'", $service);
        self::assertStringNotContainsString('mayhopphat.com', $service);

        $links = (string) file_get_contents(
            (new ReflectionClass(ArticleEditorLinksPayloadService::class))->getFileName(),
        );
        self::assertStringContainsString("'main_domain_suggestions' => \$this->mainDomainSuggestions->forArticle(\$article)", $links);

        $sidebar = $this->js('components/ArticleLinksSidebar.jsx');
        self::assertStringContainsString('main_domain_suggestions_title', $sidebar);
        self::assertStringContainsString("relationship === 'external'", $sidebar);
        self::assertStringContainsString('filterMainDomainSuggestionItems', $sidebar);
        self::assertStringContainsString('insertSuggestedLink', $sidebar);
        self::assertStringContainsString('fetchEditorLinksBase', $sidebar);
        self::assertStringNotContainsString('/editor/seo-summary', $sidebar);
    }

    public function test_heavy_editor_modules_are_dynamic_imported(): void
    {
        $shell = $this->js('article-editor.jsx');
        self::assertStringContainsString('LazyWordPressMediaRenameModal', $shell);
        self::assertStringNotContainsString('image-splitter.css', $shell);
        self::assertStringNotContainsString('/editor/seo-summary', $shell);
        self::assertStringContainsString('window.setTimeout(cb, 8000)', $shell);

        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/resources/article-resource/pages/edit-article.blade.php',
        );
        self::assertStringNotContainsString('__seoArticleOperationTracker?.bootstrap?.(this.articleId)', $blade);

        $imagesPanel = $this->js('editor/modules/article-meta/ReviewsSidebarPanel.jsx');
        self::assertStringContainsString("lazy(() => import('../../../modules/ReviewsModule'))", $imagesPanel);

        $linksPanel = $this->js('editor/modules/links/LinksSidebarPanel.jsx');
        self::assertStringContainsString("lazy(() => import('../../../components/ArticleLinksSidebar'))", $linksPanel);

        $featured = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/editor/modules/featured/FeaturedSidebarPanel.jsx',
        );
        self::assertStringContainsString("lazy(() => import('../gallery/GallerySidebarPanel.jsx')", $featured);
        self::assertStringNotContainsString("import { GallerySidebarPanel } from '../gallery/GallerySidebarPanel'", $featured);

        $unread = $this->js('chat/unreadBadge.js');
        self::assertStringContainsString('seo-article-edit-page', $unread);
        self::assertStringContainsString('window.setTimeout(start, 10000)', $unread);
    }

    public function test_editor_has_no_heartbeat_or_live_php_scorer_callers(): void
    {
        $client = $this->js('utils/editorSessionClient.js');
        self::assertStringNotContainsString('setInterval', $client);

        $analysis = $this->js('hooks/useArticleEditorSeoAnalysis.js');
        self::assertStringNotContainsString('previewSeoScoreViaApi', $analysis);
        self::assertStringNotContainsString('seo-summary', $analysis);
    }

    private function js(string $relative): string
    {
        $path = ProjectRoot::addonsPath().'/content/resources/js/'.$relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function methodLike(string $source, string $startNeedle): string
    {
        $start = strpos($source, $startNeedle);
        self::assertNotFalse($start, $startNeedle);

        return substr($source, $start, 1400);
    }
}
