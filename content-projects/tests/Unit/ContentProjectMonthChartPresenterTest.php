<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthChartPresenter;
use PHPUnit\Framework\TestCase;

final class ContentProjectMonthChartPresenterTest extends TestCase
{
    public function test_overall_progress_uses_assigned_over_team_capacity(): void
    {
        $presenter = new ContentProjectMonthChartPresenter();

        $chart = $presenter->presentWriter([
            'month' => '2026-08-01',
            'month_label' => '08/2026',
            'capacity' => ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS,
            'writer_empty' => false,
            'writer_max' => 13,
            'by_writer' => [
                ['user_id' => 1, 'name' => 'Yến Huỳnh', 'total_count' => 13, 'active_count' => 13, 'archived_count' => 0, 'capacity' => 30, 'remaining' => 17],
                ['user_id' => 2, 'name' => 'Natoli Uyên', 'total_count' => 12, 'active_count' => 12, 'archived_count' => 0, 'capacity' => 30, 'remaining' => 18],
                ['user_id' => 3, 'name' => 'Trang Nguyễn', 'total_count' => 11, 'active_count' => 11, 'archived_count' => 0, 'capacity' => 30, 'remaining' => 19],
                ['user_id' => 4, 'name' => 'Natoli Nữ', 'total_count' => 11, 'active_count' => 11, 'archived_count' => 0, 'capacity' => 30, 'remaining' => 19],
                ['user_id' => 5, 'name' => 'Natoli Quyên', 'total_count' => 11, 'active_count' => 11, 'archived_count' => 0, 'capacity' => 30, 'remaining' => 19],
                ['user_id' => 6, 'name' => 'Triệu Nguyễn Hữu', 'total_count' => 10, 'active_count' => 10, 'archived_count' => 0, 'capacity' => 30, 'remaining' => 20],
            ],
        ]);

        self::assertSame(68, $chart['total']);
        self::assertSame(6, $chart['writer_count']);
        self::assertSame(180, $chart['team_capacity']);
        self::assertSame(38, $chart['overall_progress_pct']);
        self::assertSame(43, $chart['rows'][0]['progress_pct']);
        self::assertStringContainsString('conic-gradient', $chart['donut_gradient']);
        self::assertSame(6, count($chart['visible_rows']));
        self::assertSame(0, $chart['more_count']);
    }

    public function test_domain_share_percent_and_top_limit(): void
    {
        $presenter = new ContentProjectMonthChartPresenter();

        $rows = [];
        for ($i = 1; $i <= 7; $i++) {
            $rows[] = [
                'site_id' => $i,
                'domain' => 'domain'.$i.'.com',
                'total_count' => 8 - $i,
                'active_count' => 8 - $i,
                'archived_count' => 0,
            ];
        }

        $chart = $presenter->presentDomain([
            'month' => '2026-08-01',
            'month_label' => '08/2026',
            'domain_empty' => false,
            'domain_max' => 7,
            'by_domain' => $rows,
        ]);

        // 7+6+5+4+3+2+1 = 28
        self::assertSame(28, $chart['total']);
        self::assertSame(7, count($chart['visible_rows']));
        self::assertSame(2, $chart['more_count']);
        self::assertSame(25.0, $chart['rows'][0]['share_pct']); // 7/28
        self::assertSame('#10b981', $chart['rows'][0]['color']);
        self::assertStringContainsString('#10b981', $chart['donut_gradient']);
    }

    public function test_present_domain_includes_zero_count_rows(): void
    {
        $presenter = new ContentProjectMonthChartPresenter();

        $chart = $presenter->presentDomain([
            'month' => '2026-08-01',
            'month_label' => '08/2026',
            'domain_empty' => false,
            'domain_max' => 40,
            'by_domain' => [
                ['site_id' => 1, 'domain' => 'a.test', 'total_count' => 40, 'active_count' => 40, 'archived_count' => 0],
                ['site_id' => 2, 'domain' => 'b.test', 'total_count' => 0, 'active_count' => 0, 'archived_count' => 0],
            ],
        ]);

        self::assertSame(40, $chart['total']);
        self::assertCount(2, $chart['rows']);
        self::assertCount(2, $chart['visible_rows']);
        self::assertSame(0.0, $chart['rows'][1]['share_pct']);
    }

    public function test_percent_helpers(): void
    {
        self::assertSame(38, ContentProjectMonthChartPresenter::percent(68, 180));
        self::assertSame(43, ContentProjectMonthChartPresenter::percent(13, 30));
        self::assertSame(0, ContentProjectMonthChartPresenter::percent(10, 0));
        self::assertSame(91.2, ContentProjectMonthChartPresenter::sharePercent(62, 68));
        self::assertSame('YH', ContentProjectMonthChartPresenter::initials('Yến Huỳnh'));
    }
}
