<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Seo\Services\SeoAuditScanService;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Tests\TestCase;

final class SeoAuditScanServiceTest extends TestCase
{
    public function test_missing_focus_keyword_only_uses_fast_path(): void
    {
        $service = app(SeoAuditScanService::class);

        $this->assertTrue($service->isMissingFocusKeywordOnly(
            [SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD],
            false,
            false,
        ));
    }

    public function test_aggregate_only_does_not_require_html_analysis(): void
    {
        $service = app(SeoAuditScanService::class);

        $this->assertFalse($service->isMissingFocusKeywordOnly([], true, false));
    }

    public function test_content_length_filter_no_longer_requires_runtime_html_analysis(): void
    {
        $reflection = new \ReflectionClass(SeoAuditScanService::class);

        $this->assertFalse($reflection->hasMethod('requiresHtmlAnalysis'));
        $this->assertFalse($reflection->hasMethod('scanWithHtmlAnalysis'));
    }

    public function test_articles_optimal_domain_filter_is_fail_closed(): void
    {
        $source = file_get_contents(ProjectRoot::addonsPath().'/content/src/Filament/Pages/ArticlesOptimal.php') ?: '';

        $this->assertStringContainsString('public ?int $filterSiteId = null;', $source);
        $this->assertStringContainsString('validatedFilterSiteId', $source);
        $this->assertStringContainsString("ValidationException::withMessages", $source);
        $this->assertStringContainsString("\$query->where('site_id', \$siteId);", $source);
        $this->assertStringNotContainsString('loadDefaultAuditResults();', $source);
    }

    public function test_articles_optimal_ui_uses_single_required_domain_select_and_disables_empty_scan(): void
    {
        $source = file_get_contents(LegacyAddonPath::resolve('resources/views/filament/pages/articles-optimal.blade.php')) ?: '';

        $this->assertStringContainsString('wire:model.live="filterSiteId"', $source);
        $this->assertStringContainsString('$canScan = $selectedSiteId > 0;', $source);
        $this->assertStringContainsString('@disabled(! $canScan)', $source);
        $this->assertStringNotContainsString('x-bind:disabled="@js(! $canScan)"', $source);
        $this->assertStringContainsString("__('seo-content-ai::filament.articles_optimal.domain_placeholder')", $source);
        $this->assertStringContainsString("__('seo-content-ai::filament.articles_optimal.domain_required')", $source);
        $this->assertStringNotContainsString("__('seo-content-ai::filament.articles_optimal.domain_all')", $source);
        $this->assertStringNotContainsString('multiple', $source);
    }
}
