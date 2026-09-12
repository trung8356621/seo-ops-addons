<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchive;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchivePreview;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\Content\Services\ArticleCompletedArchiveQueryService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectArchiveExportService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ArchivePreviewArticlePresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\CanonicalArchiveListDashboardBuilder;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGlobalLegacyArchive;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Archive vault = single project list (monthly + pinned Legacy articles). No Legacy tab.
 */
final class ContentProjectArchiveRestoreTest extends TestCase
{
    public function test_seo_project_resource_registers_archive_and_preview_routes(): void
    {
        $pages = SeoProjectResource::getPages();

        self::assertArrayHasKey('archive', $pages);
        self::assertArrayHasKey('archive-preview', $pages);
    }

    public function test_project_archives_url_points_to_the_archive_route(): void
    {
        $method = (new ReflectionClass(SeoProjectResource::class))->getMethod('projectArchivesUrl');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString("static::getUrl('archive')", $source);
    }

    public function test_list_seo_projects_shows_archived_and_keeps_archive_vault(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ListSeoProjects::class))->getFileName());

        self::assertStringContainsString("Actions\\Action::make('open_site_archive')", $source);
        self::assertStringContainsString('canViewProjectArchives', $source);
        self::assertStringNotContainsString('activeProjects()', $source);
        self::assertStringContainsString("SeoProjectResource::getUrl('archive')", $source);
    }

    public function test_content_project_archive_page_has_no_legacy_tab(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ContentProjectArchive::class))->getFileName());

        self::assertStringContainsString('ContentProjectArchiveExportService', $source);
        self::assertStringContainsString('restoreArchive', $source);
        self::assertStringContainsString('exportArchive', $source);
        self::assertStringContainsString('ContentProjectArchiveVaultListPresenter', $source);
        self::assertStringContainsString('clearFilters', $source);
        self::assertStringContainsString('ContentProjectGlobalLegacyArchive', $source);
        self::assertStringContainsString('applyMonthYearOrGlobal', $source);
        self::assertStringContainsString('canRestoreArchive', $source);

        self::assertStringNotContainsString('activeTab', $source);
        self::assertStringNotContainsString('setActiveTab', $source);
        self::assertStringNotContainsString('reopenArticle', $source);
        self::assertStringNotContainsString('canReopenArchivedArticles', $source);

        $viewPath = LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/content-project-archive.blade.php');
        $view = (string) file_get_contents($viewPath);
        self::assertStringContainsString('filtersOpen: false', $view);
        self::assertStringContainsString('archive_col_index', $view);
        self::assertStringContainsString('isGlobalLegacyArchive', $view);
        self::assertStringContainsString('canRestoreArchive($archive)', $view);
        self::assertStringContainsString('badgeLabel()', $view);
        self::assertStringContainsString('monthLabel()', $view);

        self::assertStringNotContainsString('archive-dashboard', $view);
        self::assertStringNotContainsString('setActiveTab', $view);
        self::assertStringNotContainsString('archive_tab_legacy', $view);
        self::assertStringNotContainsString('archive_legacy_banner', $view);
        self::assertStringNotContainsString('Legacy bài lẻ', $view);
        self::assertStringNotContainsString("activeTab === 'legacy'", $view);
    }

    public function test_legacy_dashboard_partial_removed(): void
    {
        $path = \Tests\Support\ProjectRoot::addonsPath()
            .DIRECTORY_SEPARATOR.'seo-content-ai-compat'
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'views'
            .DIRECTORY_SEPARATOR.'filament'
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'seo-project-resource'
            .DIRECTORY_SEPARATOR.'partials'
            .DIRECTORY_SEPARATOR.'archive-dashboard.blade.php';
        self::assertFileDoesNotExist($path);
    }

    public function test_archive_preview_uses_shared_canonical_list_pipeline(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ContentProjectArchivePreview::class))->getFileName());

        self::assertStringContainsString('getHeaderSummary', $source);
        self::assertStringContainsString('viewArchiveItemAction', $source);
        self::assertStringContainsString('cleanupArchiveWorkspace', $source);
        self::assertStringContainsString('cleanupArchivedWorkspace', $source);
        self::assertStringContainsString('slideOver', $source);
        self::assertStringContainsString('ArchivePreviewArticlePresenter', $source);
        self::assertStringContainsString('CanonicalArchiveListDashboardBuilder', $source);
        self::assertStringContainsString("articleViewMode = 'table'", $source);
        self::assertStringContainsString('setArticleViewMode', $source);
        self::assertStringNotContainsString('ArchiveContentProjectService::archive', $source);
        self::assertStringNotContainsString('RestoreContentProjectCommand', $source);
        self::assertStringNotContainsString('ArticleCompletedArchiveQueryService', $source);
        self::assertTrue(class_exists(ArchivePreviewArticlePresenter::class));
        self::assertTrue(class_exists(CanonicalArchiveListDashboardBuilder::class));
    }

    public function test_historical_completed_archive_query_kept_for_import_compat_not_vault_ui(): void
    {
        self::assertTrue(class_exists(ArticleCompletedArchiveQueryService::class));
        $method = (new ReflectionClass(ArticleCompletedArchiveQueryService::class))->getMethod('queryForSites');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('seo_content_archive_items', $source);
        self::assertStringNotContainsString("review_status', ArticleReviewStatus::Archived", $source);

        $page = (string) file_get_contents((new ReflectionClass(ContentProjectArchive::class))->getFileName());
        self::assertStringNotContainsString('ArticleCompletedArchiveQueryService', $page);
    }

    public function test_archive_and_export_services_exist(): void
    {
        self::assertTrue(class_exists(ArchiveContentProjectService::class));
        self::assertTrue(class_exists(ContentProjectArchiveExportService::class));

        $archiveRef = new ReflectionClass(ArchiveContentProjectService::class);
        self::assertTrue($archiveRef->hasMethod('archive'));
        self::assertTrue($archiveRef->hasMethod('restore'));
        self::assertTrue($archiveRef->hasMethod('buildSummary'));

        $exportRef = new ReflectionClass(ContentProjectArchiveExportService::class);
        self::assertTrue($exportRef->hasMethod('streamDownload'));
    }

    public function test_global_legacy_ssot_not_name_equality(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ContentProjectGlobalLegacyArchive::class))->getFileName(),
        );
        self::assertStringContainsString('META_IMPORT_SOURCE', $src);
        self::assertStringNotContainsString("=== 'Legacy articles'", $src);
    }

    private function readMethodSource(\ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
}
