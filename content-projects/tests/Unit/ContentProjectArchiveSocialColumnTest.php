<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchivePreview;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ArchivePreviewArticlePresenter;
use Omnichannel\Addons\Social\Services\ArticleSocialLinkService;
use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

final class ContentProjectArchiveSocialColumnTest extends TestCase
{
    public function test_archive_preview_uses_article_social_reporting_not_share_actions(): void
    {
        $viewPath = LegacyAddonPath::resolve(
            'resources/views/filament/resources/seo-project-resource/pages/content-project-archive-preview.blade.php'
        );
        $view = (string) file_get_contents($viewPath);

        self::assertStringContainsString('archive_preview_col_social', $view);
        self::assertStringNotContainsString('x-seo-content-ai::social-share-actions', $view);
        self::assertStringNotContainsString('fi-social-share-btn', $view);
        self::assertStringContainsString('archive_preview_social_count', $view);
        self::assertStringContainsString("mountAction('linkShare'", $view);
        self::assertStringContainsString('articleId', $view);
    }

    public function test_archive_preview_page_uses_canonical_article_social_link_service(): void
    {
        $previewSource = (string) file_get_contents(
            (new \ReflectionClass(ContentProjectArchivePreview::class))->getFileName()
        );

        self::assertStringContainsString('linkShareAction', $previewSource);
        self::assertStringContainsString(ArticleSocialLinkService::class, $previewSource);
        self::assertStringNotContainsString('ArchiveSocialLinkService', $previewSource);
        self::assertStringNotContainsString("withCount('socialLinks')", $previewSource);
        self::assertStringContainsString('countsForArticles', $previewSource);
        self::assertStringContainsString('social_links_count', $previewSource);
    }

    public function test_archive_presenter_reads_social_count_projection(): void
    {
        $presenterSource = (string) file_get_contents(
            (new \ReflectionClass(ArchivePreviewArticlePresenter::class))->getFileName()
        );

        self::assertStringContainsString('socialCountsByArticleId', $presenterSource);
        self::assertStringNotContainsString('social-share-actions', $presenterSource);
    }

    public function test_project_archive_export_is_reporting_workbook_with_child_social_rows(): void
    {
        $exportSource = (string) file_get_contents(
            (new \ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\ContentProjectArchiveExportService::class))->getFileName()
        );

        self::assertStringContainsString(ArticleSocialLinkService::class, $exportSource);
        self::assertStringContainsString('ExcelHyperlinkHelper', $exportSource);
        self::assertStringContainsString("'social_links_count' => 'Social'", $exportSource);
        self::assertStringContainsString('linksGroupedByArticle', $exportSource);
        self::assertStringContainsString('↳', $exportSource);
        self::assertStringNotContainsString('writeSeoAuditSheet', $exportSource);
        self::assertStringNotContainsString('writeWordPressSyncSheet', $exportSource);
        self::assertStringContainsString("'index_status' => 'Index'", $exportSource);
    }

    public function test_gsc_mcp_drawer_still_uses_social_share_actions_component(): void
    {
        $drawerPath = LegacyAddonPath::resolve(
            'resources/views/seo/performance-hub/partials/gsc-mcp-drawer.blade.php'
        );
        $drawer = (string) file_get_contents($drawerPath);

        self::assertStringContainsString('x-seo-content-ai::social-share-actions', $drawer);
    }

    public function test_monthly_archived_export_includes_social_child_rows(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArchivedMonthExportService::class))->getFileName()
        );

        self::assertStringContainsString(ArticleSocialLinkService::class, $src);
        self::assertStringContainsString('ContentProjectArchiveSocialExportRowExpander', $src);
        self::assertStringContainsString('appendSocialEvidenceRows', $src);
        self::assertStringContainsString('↳', file_get_contents(
            (new \ReflectionClass(\Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArchiveSocialExportRowExpander::class))->getFileName()
        ));
    }
}
