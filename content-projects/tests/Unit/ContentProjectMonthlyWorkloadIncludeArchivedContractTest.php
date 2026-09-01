<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchive;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Override: monthly production/capacity = ACTIVE + ARCHIVED execution.
 * Shared Draft excluded. Archive does not free capacity.
 */
final class ContentProjectMonthlyWorkloadIncludeArchivedContractTest extends TestCase
{
    public function test_workload_service_includes_archived_by_default(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectMonthlyWorkloadService::class))->getFileName(),
        );

        self::assertStringContainsString('SCOPE_ALL', $src);
        self::assertStringContainsString('SCOPE_ARCHIVED', $src);
        self::assertStringContainsString('active_count', $src);
        self::assertStringContainsString('archived_count', $src);
        self::assertStringContainsString('total_count', $src);
        self::assertStringContainsString('STATUS_DRAFT', $src);
        self::assertStringContainsString('intentionally includes both active and archived', $src);
        // SCOPE_ALL must not force active-only; SCOPE_ACTIVE may still filter.
        self::assertStringContainsString('SCOPE_ACTIVE', $src);
        self::assertStringContainsString('SCOPE_ARCHIVED', $src);
        self::assertSame(30, ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS);
    }

    public function test_capacity_service_includes_archived_and_exposes_remaining(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectWriterMonthlyCapacityService::class))->getFileName(),
        );

        self::assertStringContainsString('Include archived projects', $src);
        self::assertStringContainsString('itemBreakdownByUserId', $src);
        self::assertStringContainsString('remainingByUserId', $src);
        self::assertStringContainsString('MAX_WRITER_MONTHLY_ITEMS', $src);
        self::assertStringContainsString('STATUS_DRAFT', $src);
        self::assertStringNotContainsString("->whereNull('p.archived_at')", $src);
    }

    public function test_all_projects_charts_use_scope_all_by_default(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ListSeoProjects::class))->getFileName(),
        );
        // Default forMonth() uses SCOPE_ALL (active + archived execution).
        self::assertStringContainsString('forMonth', $src);
        self::assertStringContainsString('ContentProjectMonthlyWorkloadService', $src);
        self::assertStringContainsString('ContentProjectMonthChartPresenter', $src);

        $blade = LegacyAddonPath::read(
            'resources/views/filament/resources/seo-project-resource/pages/list-seo-projects.blade.php',
        );
        $charts = LegacyAddonPath::read(
            'resources/views/components/content-project-month-charts.blade.php',
        );
        // Compact UI: totals from SCOPE_ALL (active+archived) via presenter; capacity still shown.
        self::assertStringContainsString('total_count', $charts);
        self::assertStringContainsString('team_capacity', $charts);
        self::assertStringContainsString('{{ $count }} / {{ $capacity }}', $charts);
        self::assertStringContainsString('content-project-month-charts', $blade);
    }

    public function test_archived_page_uses_execution_month_and_archived_scope(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectArchive::class))->getFileName(),
        );

        self::assertStringContainsString('planningMonth', $src);
        self::assertStringContainsString('ContentProjectMonthChartPresenter', $src);
        self::assertStringContainsString('ContentProjectMonthlyWorkloadService', $src);
        self::assertStringContainsString('SCOPE_ARCHIVED', $src);
        self::assertStringContainsString('getArchivedDomainChart', $src);
        self::assertStringContainsString('getArchivedWriterChart', $src);
        self::assertStringContainsString('syncPlanningMonthToLegacyFilters', $src);
        self::assertStringContainsString('project_month', $src);
        self::assertStringContainsString('exportMonth', $src);
        self::assertStringContainsString('ContentProjectArchivedMonthExportService', $src);
        // Charts must not use archived_at as month SoT.
        self::assertStringNotContainsString("whereDate('archived_at'", $src);

        $blade = LegacyAddonPath::read(
            'resources/views/filament/resources/seo-project-resource/pages/content-project-archive.blade.php',
        );
        $charts = LegacyAddonPath::read(
            'resources/views/components/content-project-month-charts.blade.php',
        );
        self::assertStringContainsString('wire:model.live="planningMonth"', $blade);
        self::assertStringContainsString('content-project-month-charts', $blade);
        self::assertStringContainsString('chart_articles_by_domain', $charts);
        self::assertStringContainsString('chart_articles_by_writer', $charts);
        self::assertStringNotContainsString('chart_archived_by_domain', $blade);
        self::assertStringNotContainsString('chart_archived_by_writer', $blade);
        self::assertStringContainsString('archive_export_month', $blade);
        self::assertStringContainsString('wire:click="exportMonth"', $blade);
        self::assertStringContainsString('team_capacity', $charts);
    }

    public function test_domain_aggregate_uses_item_site_id_not_project_site(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectMonthlyWorkloadService::class))->getFileName(),
        );
        self::assertStringContainsString('t.site_id', $src);
        self::assertStringNotContainsString('groupBy(\'p.site_id\')', $src);
        self::assertStringNotContainsString('p.site_id as site_id', $src);
    }

    public function test_writer_remaining_formula_documented(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectWriterMonthlyCapacityService::class))->getFileName(),
        );
        self::assertStringContainsString('$capacity - $total', $src);
        self::assertStringContainsString('may be negative', $src);
    }
}
