<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects;
use Omnichannel\Addons\ContentProjects\Filament\Widgets\ContentProjectQueueHealthWidget;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftDomainSummary;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectListBucket;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class ContentProjectMonthChartContractTest extends TestCase
{
    public function test_list_page_exposes_month_driven_charts_not_queue_header_cards(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ListSeoProjects::class))->getFileName(),
        );

        self::assertStringContainsString('getDomainWorkloadChart', $src);
        self::assertStringContainsString('getWriterWorkloadChart', $src);
        self::assertStringContainsString('getCompactQueueStatus', $src);
        self::assertStringContainsString('ContentProjectMonthlyWorkloadService', $src);
        self::assertStringContainsString('ContentProjectMonthChartPresenter', $src);
        self::assertStringContainsString('forMonth', $src);
        self::assertStringContainsString('return [];', $src);
        self::assertStringNotContainsString('ContentProjectQueueHealthWidget::class', $src);
    }

    public function test_list_blade_month_above_charts(): void
    {
        $blade = LegacyAddonPath::read(
            'resources/views/filament/resources/seo-project-resource/pages/list-seo-projects.blade.php',
        );
        $charts = LegacyAddonPath::read(
            'resources/views/components/content-project-month-charts.blade.php',
        );

        $monthPos = strpos($blade, 'wire:model.live="planningMonth"');
        $chartsPos = strpos($blade, 'content-project-month-charts');
        $tablePos = strpos($blade, '$this->table');

        self::assertNotFalse($monthPos);
        self::assertNotFalse($chartsPos);
        self::assertNotFalse($tablePos);
        self::assertLessThan($chartsPos, $monthPos);
        self::assertLessThan($tablePos, $chartsPos);
        self::assertStringContainsString('chart_articles_by_domain', $charts);
        self::assertStringContainsString('chart_articles_by_writer', $charts);
        self::assertStringContainsString('chart_domain_empty', $charts);
        self::assertStringContainsString('chart_writer_empty', $charts);
        self::assertStringContainsString('content-project-month-charts', $blade);
        self::assertStringContainsString('minmax(0, 1fr) minmax(0, 1fr)', $charts);
        self::assertStringContainsString('donut_gradient', $charts);
        self::assertStringContainsString('overall_progress_pct', $charts);
        self::assertStringContainsString('team_capacity', $charts);
        self::assertStringContainsString('visible_rows', $charts);
        self::assertStringContainsString('getCompactQueueStatus', (string) file_get_contents(
            (string) (new ReflectionClass(ListSeoProjects::class))->getFileName(),
        ));
        self::assertStringContainsString('queue_healthy', (string) file_get_contents(
            (string) (new ReflectionClass(ListSeoProjects::class))->getFileName(),
        ));
    }

    public function test_workload_service_includes_archived_and_excludes_draft(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectMonthlyWorkloadService::class))->getFileName(),
        );

        self::assertStringContainsString('STATUS_DRAFT', $src);
        self::assertStringContainsString('t.site_id', $src);
        self::assertStringContainsString('articlesByDomain', $src);
        self::assertStringContainsString('articlesByWriter', $src);
        self::assertStringContainsString('MAX_WRITER_MONTHLY_ITEMS', $src);
        self::assertStringContainsString('SCOPE_ALL', $src);
        self::assertStringContainsString('active_count', $src);
        self::assertStringContainsString('archived_count', $src);
        self::assertSame(30, ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS);
    }

    public function test_list_bucket_draft_ignores_month(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectListBucket::class))->getFileName(),
        );
        self::assertStringContainsString('Draft has no execution month', $src);
        self::assertStringContainsString('STATUS_DRAFT', $src);
    }

    public function test_draft_domain_summary_service_exists(): void
    {
        self::assertTrue(class_exists(PlanningDraftDomainSummary::class));
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PlanningDraftDomainSummary::class))->getFileName(),
        );
        self::assertStringContainsString('t.site_id', $src);
        self::assertStringContainsString('isDraftPlanning', $src);
    }

    public function test_queue_widget_class_still_exists_for_secondary_use(): void
    {
        self::assertTrue(class_exists(ContentProjectQueueHealthWidget::class));
    }
}
