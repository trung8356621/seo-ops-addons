<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Statistics\UserStatisticsReadModel;
use Omnichannel\Addons\SearchFoundation\Filament\Pages\Statistics;
use Tests\TestCase;

final class StatisticsUserContractTest extends TestCase
{
    public function test_user_read_model_uses_capacity_ssot_and_analytics_page_scope(): void
    {
        $src = (string) file_get_contents((string) (new \ReflectionClass(UserStatisticsReadModel::class))->getFileName());
        $this->assertStringContainsString('ContentProjectWriterCapacitySettingsService', $src);
        $this->assertStringContainsString('seo_projects.month', $src);
        $this->assertStringContainsString('SeoAnalyticsArticleScope', $src);
        $this->assertStringContainsString('applyToTaskArticleId', $src);
        $this->assertStringNotContainsString('t.post_type', $src);
        $this->assertStringNotContainsString('OperationalLandingDashboardReadModel', $src);
        $this->assertStringNotContainsString('teamProductivity', $src);
    }

    public function test_statistics_page_wires_user_filter_without_ranking_language(): void
    {
        $page = (string) file_get_contents((string) (new \ReflectionClass(Statistics::class))->getFileName());
        $this->assertStringContainsString('filterUserId', $page);

        $blade = (string) file_get_contents(dirname(__DIR__, 3)
            .DIRECTORY_SEPARATOR.'seo-content-ai-compat'
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'views'
            .DIRECTORY_SEPARATOR.'filament'
            .DIRECTORY_SEPARATOR.'pages'
            .DIRECTORY_SEPARATOR.'statistics'
            .DIRECTORY_SEPARATOR.'user-tab.blade.php');

        $this->assertStringContainsString('ops-statistics-workload', $blade);
        $this->assertStringContainsString('col_capacity', $blade);
        $this->assertStringNotContainsString('best', strtolower($blade));
        $this->assertStringNotContainsString('worst', strtolower($blade));
        $this->assertStringNotContainsString('xếp hạng', strtolower($blade));
    }

    public function test_locale_keys_exist_for_user_and_domain_empty_states(): void
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

        foreach (['empty_assignments', 'empty_scores', 'empty_period', 'tab_domain', 'tab_user'] as $key) {
            $this->assertArrayHasKey($key, $vi['statistics']);
            $this->assertArrayHasKey($key, $en['statistics']);
        }
    }
}
