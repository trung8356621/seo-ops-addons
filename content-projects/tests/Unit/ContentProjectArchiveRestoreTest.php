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
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guard: kho archive = tab dự án (SeoProjectArchive) + tab legacy bài lẻ.
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

    public function test_content_project_archive_page_has_project_and_legacy_tabs(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ContentProjectArchive::class))->getFileName());

        self::assertStringContainsString('ArchiveContentProjectService', $source);
        self::assertStringContainsString('ContentProjectArchiveExportService', $source);
        self::assertStringContainsString('restoreArchive', $source);
        self::assertStringContainsString('exportArchive', $source);
        self::assertStringContainsString('reopenArticle', $source);
        self::assertStringContainsString("activeTab = 'projects'", $source);
        self::assertStringContainsString('ContentProjectArchiveVaultListPresenter', $source);
        self::assertStringContainsString('clearFilters', $source);

        $viewPath = LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/content-project-archive.blade.php');
        $view = (string) file_get_contents($viewPath);
        self::assertStringContainsString('archive-dashboard', $view);
        self::assertStringContainsString('setActiveTab', $view);
        self::assertStringContainsString('archive_tab_legacy', $view);
        self::assertStringContainsString('filtersOpen: false', $view);
        self::assertStringContainsString('archive_col_index', $view);
    }

    public function test_archive_preview_page_is_read_only(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ContentProjectArchivePreview::class))->getFileName());

        self::assertStringContainsString('getHeaderSummary', $source);
        self::assertStringContainsString('viewArchiveItemAction', $source);
        self::assertStringContainsString('cleanupArchiveWorkspace', $source);
        self::assertStringContainsString('cleanupArchivedWorkspace', $source);
        self::assertStringContainsString('slideOver', $source);
        self::assertStringContainsString('ArchivePreviewArticlePresenter', $source);
        self::assertStringNotContainsString('ArchiveContentProjectService::archive', $source);
        self::assertStringNotContainsString('RestoreContentProjectCommand', $source);
    }

    public function test_legacy_query_uses_content_archived_flag_not_review_status_alone(): void
    {
        $method = (new ReflectionClass(ArticleCompletedArchiveQueryService::class))->getMethod('queryForSites');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('content_archived_at', $source);
        self::assertStringContainsString('seo_content_archive_items', $source);
        self::assertStringNotContainsString("review_status', ArticleReviewStatus::Archived", $source);
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

    private function readMethodSource(\ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
}
