<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Compact Article Editor header: no informational domain banner; prominent Review CTA.
 */
final class ArticleEditorHeaderReviewCompactTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function editArticleBlade(): string
    {
        return (string) file_get_contents(
            $this->resolveLegacyOrMovedAddonPath(
                'resources/views/filament/resources/article-resource/pages/edit-article.blade.php',
            ),
        );
    }

    private function pageActionsBlade(): string
    {
        return (string) file_get_contents(
            $this->resolveLegacyOrMovedAddonPath(
                'resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php',
            ),
        );
    }

    private function editPageCss(): string
    {
        return (string) file_get_contents(
            $this->resolveLegacyOrMovedAddonPath('resources/css/article-edit-page.css'),
        );
    }

    public function test_informational_domain_banner_is_not_rendered_on_editor(): void
    {
        $blade = $this->editArticleBlade();

        self::assertStringNotContainsString('record_domain_note', $blade);
        self::assertStringNotContainsString('border-warning-300 bg-warning-50', $blade);
        self::assertStringContainsString('recordDomainDiffersFromGlobal', $blade);
    }

    public function test_edit_article_still_tracks_domain_mismatch_without_forcing_site_switch(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(EditArticle::class))->getFileName());

        self::assertStringContainsString('recordDomainDiffersFromGlobal', $source);
        self::assertStringContainsString('globalSiteId !== $articleSiteId', $source);
        self::assertStringNotContainsString('SeoAccessControl::setGlobalSiteId($articleSiteId)', $source);
        self::assertStringNotContainsString('syncGlobalSiteForArticle($this->record)', $source);
    }

    public function test_review_cluster_is_primary_toolbar_not_desktop_only(): void
    {
        $actions = $this->pageActionsBlade();

        self::assertStringContainsString('class="seo-editor-review-cluster"', $actions);
        self::assertStringNotContainsString(
            'class="seo-editor-review-cluster seo-editor-page-actions__desktop-only"',
            $actions,
        );
        self::assertStringContainsString('data-seo-page-action="review-cta"', $actions);
        self::assertStringContainsString('data-seo-page-action="review-badge"', $actions);
        self::assertStringContainsString('reviewShowsStatusBadge()', $actions);
        self::assertStringContainsString('reviewCtaLabel()', $actions);
        self::assertStringContainsString('reviewIsPendingCta()', $actions);
    }

    public function test_review_reuses_canonical_handler_and_does_not_run_on_save(): void
    {
        $actions = $this->pageActionsBlade();

        self::assertStringContainsString('submitReviewAction(', $actions);
        self::assertStringContainsString('reviewEndpoint', $actions);
        self::assertStringContainsString("detail: { action: 'save' }", $actions);
        self::assertStringContainsString('data-seo-page-action="review-cta"', $actions);

        // Save click dispatches editor shortcut only — never the review POST helper.
        self::assertDoesNotMatchRegularExpression(
            "/action:\\s*'save'[^\"']{0,120}submitReviewAction/",
            $actions,
        );
        self::assertDoesNotMatchRegularExpression(
            "/submitReviewAction[^\"']{0,120}action:\\s*'save'/",
            $actions,
        );
    }

    public function test_review_bootstrap_still_comes_from_article_review_service(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(EditArticle::class))->getFileName());
        $method = (new ReflectionClass(EditArticle::class))->getMethod('getArticleReviewBootstrap');
        $body = implode('', array_slice(
            (array) file((string) $method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringContainsString('ArticleReviewService', $body);
        self::assertStringContainsString('toApiPayload', $body);
        self::assertStringContainsString('available_actions', $body);
        self::assertStringContainsString('getArticleReviewBootstrap', $source);
    }

    public function test_responsive_css_keeps_review_visible_and_hides_preview_not_review_split(): void
    {
        $css = $this->editPageCss();

        self::assertStringContainsString('.seo-editor-toolbar-btn--review-cta', $css);
        self::assertStringContainsString('.seo-editor-page-actions .seo-editor-review-cluster', $css);
        self::assertStringContainsString('[data-seo-page-action="preview"]', $css);
        self::assertStringNotContainsString(
            ".seo-editor-page-actions [data-seo-page-action=\"sync\"],\n    .seo-editor-page-actions .seo-editor-preview-split {",
            $css,
        );
    }

    public function test_domain_integrity_blocking_page_still_exists(): void
    {
        $path = $this->resolveLegacyOrMovedAddonPath(
            'resources/views/filament/resources/article-resource/pages/article-domain-mismatch.blade.php',
        );
        $blade = (string) file_get_contents($path);

        self::assertStringContainsString('domain_mismatch_heading', $blade);
        self::assertStringContainsString('domain_mismatch_description', $blade);
    }
}
