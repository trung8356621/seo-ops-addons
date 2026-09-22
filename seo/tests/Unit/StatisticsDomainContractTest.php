<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Filament\Pages\Statistics;
use Omnichannel\Addons\Seo\Services\Statistics\DomainStatisticsReadModel;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoPanelRoutes;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class StatisticsDomainContractTest extends TestCase
{
    public function test_statistics_page_exists_and_is_planner_gated(): void
    {
        $src = (string) file_get_contents((string) (new \ReflectionClass(Statistics::class))->getFileName());
        $this->assertStringContainsString('canAccessPlannerFeatures', $src);
        $this->assertStringContainsString("slug = 'statistics'", $src);
        $this->assertStringContainsString('DomainStatisticsReadModel', $src);
        $this->assertStringContainsString('UserStatisticsReadModel', $src);
        $this->assertStringContainsString("#[Url(as: 'tab'", $src);
        $this->assertStringContainsString("#[Url(as: 'month'", $src);
        $this->assertStringContainsString("#[Url(as: 'compare'", $src);
    }

    public function test_statistics_page_loads_only_active_tab_read_model(): void
    {
        $src = (string) file_get_contents((string) (new \ReflectionClass(Statistics::class))->getFileName());
        $this->assertMatchesRegularExpression(
            '/if \(\$this->tab === \'user\'\).*UserStatisticsReadModel.*else.*DomainStatisticsReadModel/s',
            $src,
        );
        $this->assertStringNotContainsString('OperationalLandingDashboardReadModel', $src);
    }

    public function test_domain_read_model_documents_period_semantics_and_threshold(): void
    {
        $src = (string) file_get_contents((string) (new \ReflectionClass(DomainStatisticsReadModel::class))->getFileName());
        $this->assertStringContainsString('seo_eligible_inventory_snapshot', $src);
        $this->assertStringContainsString('articles.created_at_day_in_month', $src);
        $this->assertStringContainsString('AUDIT_LOW_SCORE_THRESHOLD', $src);
        $this->assertSame(60, SeoScoringRulesRegistry::AUDIT_LOW_SCORE_THRESHOLD);
        $this->assertStringContainsString('URGENT_LIMIT', $src);
        $this->assertSame(8, DomainStatisticsReadModel::URGENT_LIMIT);
        $this->assertStringContainsString('previous_chart', $src);
        $this->assertStringContainsString('if ($compare)', $src);
    }

    public function test_domain_bands_match_domain_overview_ssot(): void
    {
        $src = (string) file_get_contents((string) (new \ReflectionClass(DomainStatisticsReadModel::class))->getFileName());
        $this->assertStringContainsString('seo_score < 50', $src);
        $this->assertStringContainsString('>= 50 AND sap_stats.seo_score < 70', $src);
        $this->assertStringContainsString('>= 70 AND sap_stats.seo_score < 90', $src);
        $this->assertStringContainsString('>= 90', $src);
    }

    public function test_statistics_hides_global_domain_picker(): void
    {
        Route::get('/seo/statistics', fn () => 'ok')->name('filament.seo.pages.statistics');
        $request = Request::create('/seo/statistics', 'GET');
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);

        $this->assertTrue(SeoPanelRoutes::isStatisticsPage());
        $this->assertFalse(SeoAccessControl::shouldShowGlobalSitePicker());
    }

    public function test_blade_shell_has_tabs_filters_row_and_no_inline_layout_css(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 3)
            .DIRECTORY_SEPARATOR.'seo-content-ai-compat'
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'views'
            .DIRECTORY_SEPARATOR.'filament'
            .DIRECTORY_SEPARATOR.'pages'
            .DIRECTORY_SEPARATOR.'statistics.blade.php');

        $this->assertStringContainsString('ops-statistics__utility', $blade);
        $this->assertStringContainsString('ops-statistics__tabs', $blade);
        $this->assertStringContainsString('ops-statistics__filters', $blade);
        $this->assertStringContainsString("wire:model.live=\"compare\"", $blade);
        $this->assertStringNotContainsString('@vite(', $blade);
        $this->assertStringNotContainsString('style="display:grid', $blade);

        $provider = (string) file_get_contents(dirname(__DIR__, 2)
            .DIRECTORY_SEPARATOR.'src'
            .DIRECTORY_SEPARATOR.'SeoServiceProvider.php');
        $this->assertStringContainsString('ops-statistics.css', $provider);
    }

    public function test_export_is_deferred_disabled(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 3)
            .DIRECTORY_SEPARATOR.'seo-content-ai-compat'
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'views'
            .DIRECTORY_SEPARATOR.'filament'
            .DIRECTORY_SEPARATOR.'pages'
            .DIRECTORY_SEPARATOR.'statistics.blade.php');
        $this->assertStringContainsString('ops-statistics__export', $blade);
        $this->assertStringContainsString('disabled', $blade);
        $this->assertStringContainsString('export_deferred', $blade);
    }
}
