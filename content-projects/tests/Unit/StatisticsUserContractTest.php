<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Statistics\UserStatisticsReadModel;
use Omnichannel\Addons\SearchFoundation\Filament\Pages\Statistics;
use Tests\TestCase;

final class StatisticsUserContractTest extends TestCase
{
    public function test_user_read_model_is_review_workload_only(): void
    {
        $src = (string) file_get_contents((string) (new \ReflectionClass(UserStatisticsReadModel::class))->getFileName());
        $this->assertStringContainsString('content_manager_reviewed_at', $src);
        $this->assertStringContainsString('content_manager_reviewed_by', $src);
        $this->assertStringContainsString('ContentProjectStaffAvailabilityService', $src);
        $this->assertStringContainsString('SeoAnalyticsArticleScope', $src);
        $this->assertStringContainsString('applyNeedsReviewConstraints', $src);
        $this->assertStringContainsString('applyInReviewConstraints', $src);
        $this->assertStringNotContainsString('ContentProjectWriterMonthlyCapacityService', $src);
        $this->assertStringNotContainsString('t.post_type', $src);
        $this->assertStringNotContainsString('teamProductivity', $src);
        $this->assertStringNotContainsString('OperationalLandingDashboardReadModel', $src);
    }

    public function test_user_tab_blade_has_two_widgets_only_no_detail_table(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 3)
            .DIRECTORY_SEPARATOR.'seo-content-ai-compat'
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'views'
            .DIRECTORY_SEPARATOR.'filament'
            .DIRECTORY_SEPARATOR.'pages'
            .DIRECTORY_SEPARATOR.'statistics'
            .DIRECTORY_SEPARATOR.'user-tab.blade.php');

        $this->assertStringContainsString('completed_volume_title', $blade);
        $this->assertStringContainsString('avg_score_title', $blade);
        $this->assertStringContainsString('ops-statistics-hbar', $blade);
        $this->assertStringContainsString('ops-statistics-scorelist', $blade);
        $this->assertStringContainsString('kpi_cms_with_work', $blade);
        $this->assertStringContainsString('kpi_waiting_review', $blade);
        $this->assertStringContainsString('kpi_reviewed_in_month', $blade);
        $this->assertStringContainsString('kpi_in_review', $blade);

        $this->assertStringNotContainsString('workload_title', $blade);
        $this->assertStringNotContainsString('users_table_title', $blade);
        $this->assertStringNotContainsString('col_capacity', $blade);
        $this->assertStringNotContainsString('Tổng điểm SEO', $blade);
        $this->assertStringNotContainsString('Chi tiết theo người dùng', $blade);
    }

    public function test_statistics_page_lists_content_managers_only(): void
    {
        $page = (string) file_get_contents((string) (new \ReflectionClass(Statistics::class))->getFileName());
        $this->assertStringContainsString('contentManagerNameOptions', $page);
        $this->assertStringContainsString('ContentProjectStaffAvailabilityService', $page);
        $this->assertStringContainsString('content_manager_options', $page);
    }

    public function test_locale_keys_for_review_user_tab(): void
    {
        $vi = include dirname(__DIR__, 3)
            .DIRECTORY_SEPARATOR.'seo-content-ai-compat'
            .DIRECTORY_SEPARATOR.'lang'
            .DIRECTORY_SEPARATOR.'vi'
            .DIRECTORY_SEPARATOR.'filament.php';
        $en = include dirname(__DIR__, 3)
            .DIRECTORY_SEPARATOR.'seo-content-ai-compat'
            .DIRECTORY_SEPARATOR.'lang'
            .DIRECTORY_SEPARATOR.'en'
            .DIRECTORY_SEPARATOR.'filament.php';

        foreach ([
            'kpi_cms_with_work',
            'kpi_waiting_review',
            'kpi_reviewed_in_month',
            'kpi_in_review',
            'completed_volume_title',
            'avg_score_title',
            'completed_volume_empty',
            'avg_score_empty',
            'filter_all_cms',
        ] as $key) {
            $this->assertArrayHasKey($key, $vi['statistics']);
            $this->assertArrayHasKey($key, $en['statistics']);
        }
    }
}
