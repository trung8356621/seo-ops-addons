<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchivePreview;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArchivedMonthExportService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectArchiveExportService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ArchivePreviewArticlePresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guard: Content Project archive/export must not own social reporting.
 */
final class ContentProjectArchiveSocialRetirementContractTest extends TestCase
{
    public function test_archive_preview_has_no_social_reporting(): void
    {
        $previewSource = (string) file_get_contents(
            (new ReflectionClass(ContentProjectArchivePreview::class))->getFileName()
        );
        $presenterSource = (string) file_get_contents(
            (new ReflectionClass(ArchivePreviewArticlePresenter::class))->getFileName()
        );
        $view = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/resources/seo-project-resource/pages/content-project-archive-preview.blade.php'
        );
        $listView = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/resources/seo-project-resource/partials/archive-preview-list.blade.php'
        );

        self::assertStringNotContainsString('ArticleSocialLinkService', $previewSource);
        self::assertStringNotContainsString('linkShareAction', $previewSource);
        self::assertStringNotContainsString('social_links_count', $previewSource);
        self::assertStringNotContainsString('social_links_count', $presenterSource);
        self::assertStringNotContainsString('social_links_count', $view);
        self::assertStringNotContainsString("mountAction('linkShare'", $view);
        self::assertStringNotContainsString("mountAction('linkShare'", $listView);
        self::assertStringNotContainsString('archive_preview_col_social', $view);
    }

    public function test_archive_exports_have_no_social_rows_or_columns(): void
    {
        $exportSource = (string) file_get_contents(
            (new ReflectionClass(ContentProjectArchiveExportService::class))->getFileName()
        );
        $monthSource = (string) file_get_contents(
            (new ReflectionClass(ContentProjectArchivedMonthExportService::class))->getFileName()
        );

        self::assertStringNotContainsString('ArticleSocialLinkService', $exportSource);
        self::assertStringNotContainsString('ArticleSocialLinkService', $monthSource);
        self::assertStringNotContainsString('social_links_count', $exportSource);
        self::assertStringNotContainsString('ContentProjectArchiveSocialExportRowExpander', $monthSource);
        self::assertStringNotContainsString('appendSocialEvidenceRows', $monthSource);
        self::assertStringNotContainsString('Tổng Social links', $exportSource);
        self::assertFileDoesNotExist(
            dirname(__DIR__, 2).'/src/Support/ContentProject/ContentProjectArchiveSocialExportRowExpander.php'
        );
    }

    public function test_retired_social_link_api_and_storage_are_gone(): void
    {
        $provider = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/Providers/SeoPanelProvider.php'
        );
        self::assertStringNotContainsString('ArticleSocialLinkController', $provider);
        self::assertStringNotContainsString('social-links', $provider);
        self::assertFileDoesNotExist(
            dirname(__DIR__, 3).'/social/src/Services/ArticleSocialLinkService.php'
        );
        self::assertFileDoesNotExist(
            dirname(__DIR__, 3).'/social/src/Models/SeoArticleSocialLink.php'
        );
    }
}
