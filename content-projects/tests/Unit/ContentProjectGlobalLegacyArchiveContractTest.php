<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchive;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchivePreview;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RestoreContentProjectHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGlobalLegacyArchive;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectGlobalLegacyArchiveContractTest extends TestCase
{
    public function test_predicate_uses_meta_import_source_not_name(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ContentProjectGlobalLegacyArchive::class))->getFileName(),
        );

        self::assertStringContainsString('seo_content_archive_items', $src);
        self::assertStringContainsString('import_source', $src);
        self::assertStringContainsString('isGlobalLegacyArchive', $src);
        self::assertStringContainsString('applyMonthYearOrGlobal', $src);
        self::assertStringContainsString('excludeFromProjectAlias', $src);
        self::assertStringContainsString('orderPinnedFirst', $src);
        self::assertStringNotContainsString("=== 'Legacy articles'", $src);
    }

    public function test_list_page_uses_global_month_or_and_restore_guard(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ContentProjectArchive::class))->getFileName());

        self::assertStringContainsString('ContentProjectGlobalLegacyArchive', $src);
        self::assertStringContainsString('applyMonthYearOrGlobal', $src);
        self::assertStringContainsString('orderPinnedFirst', $src);
        self::assertStringContainsString('canRestoreArchive', $src);
        self::assertStringContainsString('archive_legacy_restore_forbidden', $src);
        self::assertStringNotContainsString("where('project_month', (int) \$this->monthFilter)", $src);
    }

    public function test_workload_excludes_global_legacy(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ContentProjectMonthlyWorkloadService::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectGlobalLegacyArchive::excludeFromProjectAlias', $src);
    }

    public function test_restore_handlers_reject_global_legacy(): void
    {
        $handler = (string) file_get_contents(
            (new ReflectionClass(RestoreContentProjectHandler::class))->getFileName(),
        );
        $service = (string) file_get_contents(
            (new ReflectionClass(ArchiveContentProjectService::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectGlobalLegacyArchive', $handler);
        self::assertStringContainsString('archive_legacy_restore_forbidden', $handler);
        self::assertStringContainsString('ContentProjectGlobalLegacyArchive', $service);
        self::assertStringContainsString('archive_legacy_restore_forbidden', $service);
    }

    public function test_preview_exposes_non_monthly_period_label(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ContentProjectArchivePreview::class))->getFileName(),
        );

        self::assertStringContainsString('period_label', $src);
        self::assertStringContainsString('is_global_legacy', $src);
        self::assertStringContainsString('ContentProjectGlobalLegacyArchive::monthLabel', $src);
    }

    public function test_blade_hides_restore_and_shows_badge_not_db_month(): void
    {
        $view = (string) file_get_contents(
            dirname(__DIR__, 3)
            .DIRECTORY_SEPARATOR.'seo-content-ai-compat'
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'views'
            .DIRECTORY_SEPARATOR.'filament'
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'seo-project-resource'
            .DIRECTORY_SEPARATOR.'pages'
            .DIRECTORY_SEPARATOR.'content-project-archive.blade.php',
        );

        self::assertStringContainsString('isGlobalLegacyArchive', $view);
        self::assertStringContainsString('canRestoreArchive($archive)', $view);
        self::assertStringContainsString('monthLabel()', $view);
        self::assertStringContainsString('badgeLabel()', $view);
        self::assertStringContainsString('bg-amber-50/70', $view);
    }
}
