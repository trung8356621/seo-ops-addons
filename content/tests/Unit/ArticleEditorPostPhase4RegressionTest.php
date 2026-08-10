<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorLazyPayloadController;
use Omnichannel\Addons\Commerce\Http\Controllers\ArticleProductReviewStatusController;
use Omnichannel\Addons\Content\Services\ArticleEditorLinksPayloadService;
use Omnichannel\Addons\Commerce\Services\ProductReview\WordPressProductReviewStatusService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Post Phase 4 stabilization â€” static contracts for regression fixes.
 * Remote-first: source asserts only (no HTTP / no DB).
 */
final class ArticleEditorPostPhase4RegressionTest extends TestCase
{
    public function test_product_review_status_controller_imports_creation_policy(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleProductReviewStatusController::class))->getFileName(),
        );

        self::assertStringContainsString(
            'use Omnichannel\\Addons\\Commerce\\Services\\ProductReview\\ProductReviewCreationPolicy;',
            $source,
        );
    }

    public function test_review_status_payload_exposes_applicable_status_count(): void
    {
        $body = $this->methodBody(WordPressProductReviewStatusService::class, 'payload');

        self::assertStringContainsString("'applicable'", $body);
        self::assertStringContainsString("'status'", $body);
        self::assertStringContainsString("'count'", $body);
        self::assertStringContainsString('resolvePublicStatus', $body);
    }

    public function test_faqs_endpoint_never_returns_null_items_contract(): void
    {
        $body = $this->methodBody(ArticleEditorLazyPayloadController::class, 'faqs');

        self::assertStringContainsString("'cached' => false", $body);
        self::assertStringContainsString("'cached_at' => null", $body);
        self::assertStringContainsString("'items' => \$items", $body);
        self::assertStringContainsString("'count' => count(\$items)", $body);
        self::assertStringContainsString("'faqs' => \$items", $body);
        self::assertStringContainsString("'can_generate'", $body);
        self::assertStringContainsString("'message' => null", $body);
    }

    public function test_links_base_includes_can_generate_suggestions(): void
    {
        $body = $this->methodBody(ArticleEditorLinksPayloadService::class, 'base');

        self::assertStringContainsString("'can_generate_suggestions' => true", $body);
        self::assertStringContainsString("'domain_cta_list'", $body);
    }

    public function test_frontend_payload_adapters_exist(): void
    {
        $path = ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorPayloadAdapters.js';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        foreach ([
            'unwrapModuleEnvelope',
            'normalizeModulePayload',
            'logModuleLoadError',
            'normalizeSeoSummary',
            'normalizeFaqPayload',
            'normalizeLinksPayload',
            'normalizeCtaPayload',
            'normalizeReviewStatus',
            'readCoreBootstrap',
            'readCoreArticleIdentity',
        ] as $fn) {
            self::assertStringContainsString("export function {$fn}", $source);
        }

        self::assertStringContainsString('cached: Boolean(base.cached)', $source);
        self::assertStringContainsString('cached_at: base.cached_at', $source);
        self::assertStringContainsString("error: payload == null ? 'EMPTY_PAYLOAD'", $source);
        self::assertStringContainsString('canGenerateFaq: canGenerateRaw !== false', $source);
        self::assertStringContainsString('Array.isArray(payload)', $source);
    }

    public function test_faq_removed_from_assistant_and_links_ui(): void
    {
        $navigator = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/js/utils/seoAssistantNavigator.js',
        );
        $links = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleLinksSidebar.jsx',
        );
        $modules = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorModules.js',
        );
        $faqModule = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/editor/modules/faq/index.js',
        );
        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );

        self::assertStringNotContainsString("id: 'faq'", $navigator);
        self::assertFileDoesNotExist(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleEditorModuleHost.jsx',
        );
        self::assertStringNotContainsString('FAQ (', $links);
        self::assertStringNotContainsString('seo-editor-faqs-updated', $links);
        self::assertStringContainsString('seo-editor-links-rescan-request', $links);
        self::assertStringContainsString("MODULE_EVENT_OPEN = 'article-editor:module-open'", $modules);
        self::assertStringContainsString('FaqSidebarPanel', $faqModule);
        self::assertStringContainsString('MODULE_EVENT_OPEN', $editor);
        self::assertStringContainsString('LINKS_RESCAN_REQUEST_EVENT', $editor);
        self::assertStringContainsString('scanExistingLinksCompat', $editor);
    }

    public function test_existing_links_scanner_fixture_documents_git_regression_counts(): void
    {
        $scanner = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/existingLinkScanner.js',
        );
        $legacy = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleLinkScroll.js',
        );
        $fixture = (string) file_get_contents(
            __DIR__.'/fixtures/existing-links-scan-sample.html',
        );

        self::assertStringContainsString('export function scanExistingLinksFromBlocks', $scanner);
        self::assertStringContainsString('export function classifyLinkHref', $scanner);
        self::assertStringContainsString('mailto:', $scanner);
        self::assertStringContainsString('extractLinksFromBlocks', $legacy);
        self::assertStringContainsString('isInternalLinkHref', $legacy);
        // Fixture: /about + same-domain absolute = internal; other-site = external; mailto/tel/# ignored.
        self::assertStringContainsString('href="/about"', $fixture);
        self::assertStringContainsString('https://example.com/pricing', $fixture);
        self::assertStringContainsString('https://other-site.test/x', $fixture);
        self::assertStringContainsString('expected existing-link scan = 2 internal + 1 external', $fixture);
    }

    public function test_links_sidebar_reads_core_identity_not_only_removed_meta(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleLinksSidebar.jsx',
        );

        self::assertStringContainsString('readCoreArticleIdentity', $source);
        self::assertStringContainsString('normalizeLinksPayload', $source);
        self::assertStringContainsString('editor/links/suggestions', $source);
        self::assertStringNotContainsString('forArticle(', $source);
        self::assertStringContainsString('setSuggestionsError', $source);
        self::assertStringContainsString('AbortController', $source);
    }

    public function test_faq_and_links_runtime_modules_receive_article_id(): void
    {
        $faq = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/editor/modules/faq/FaqSidebarPanel.jsx',
        );
        $links = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/editor/modules/links/LinksSidebarPanel.jsx',
        );

        self::assertStringContainsString('articleId', $faq);
        self::assertStringContainsString('articleId', $links);
        self::assertFileDoesNotExist(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleEditorModuleHost.jsx',
        );
    }

    public function test_seo_article_editor_defaults_seo_module_and_terminates_loading(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );

        self::assertStringContainsString("useState('seo')", $source);
        self::assertStringContainsString('seoSummaryLoading', $source);
        self::assertStringContainsString('normalizeSeoSummary', $source);
        self::assertStringContainsString('loading={seoSummaryLoading}', $source);
        self::assertStringContainsString('editor_panel_lazy_placeholder', $source);
    }

    public function test_article_editor_builds_light_serp_from_core_bootstrap(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );

        self::assertStringContainsString('google_serp_preview', $source);
        self::assertStringContainsString('metaDescription', $source);
        self::assertStringContainsString('permalinkBase', $source);
        self::assertStringContainsString('seo-article-core-bootstrap', $source);
    }

    public function test_google_preview_prefers_local_title_and_live_url(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/js/components/ArticleGoogleSerpPreview.jsx',
        );

        self::assertStringContainsString('buildLiveDisplayUrl', $source);
        self::assertStringContainsString('titleFromArticle', $source);
        self::assertStringContainsString('readArticleMetaFromDom', $source);
    }

    public function test_reviews_tab_normalizes_status_and_survives_failure(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleReviewsTab.jsx',
        );

        self::assertStringContainsString('normalizeReviewStatus', $source);
        self::assertStringContainsString('setStatusLoading(false)', $source);
        self::assertStringContainsString('catch (error)', $source);
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
