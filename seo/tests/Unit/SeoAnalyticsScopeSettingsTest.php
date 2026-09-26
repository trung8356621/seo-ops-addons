<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsGeneral;
use Omnichannel\Addons\Seo\Services\SeoAnalyticsScopeSettingsService;
use Omnichannel\Addons\Seo\Services\Statistics\DomainStatisticsReadModel;
use Omnichannel\Addons\Seo\Support\SeoAnalyticsArticleScope;
use Omnichannel\Addons\Seo\Services\SiteContext\Support\EligibleContentScope;
use Tests\TestCase;

final class SeoAnalyticsScopeSettingsTest extends TestCase
{
    public function test_missing_setting_defaults_to_true(): void
    {
        $service = SeoAnalyticsScopeSettingsService::withDefaults();
        $this->assertTrue($service->excludePagesFromStatistics());

        $empty = new SeoAnalyticsScopeSettingsService;
        // Force normalize of empty stored payload
        $normalized = $empty->normalize([]);
        $this->assertTrue($normalized[SeoAnalyticsScopeSettingsService::KEY_EXCLUDE_PAGES_FROM_STATISTICS]);
    }

    public function test_can_save_true_and_false(): void
    {
        $service = SeoAnalyticsScopeSettingsService::withDefaults();

        $service->save([SeoAnalyticsScopeSettingsService::KEY_EXCLUDE_PAGES_FROM_STATISTICS => false]);
        $this->assertFalse($service->excludePagesFromStatistics());

        $service->save([SeoAnalyticsScopeSettingsService::KEY_EXCLUDE_PAGES_FROM_STATISTICS => true]);
        $this->assertTrue($service->excludePagesFromStatistics());

        $service->save([SeoAnalyticsScopeSettingsService::KEY_EXCLUDE_PAGES_FROM_STATISTICS => '0']);
        $this->assertFalse($service->excludePagesFromStatistics());

        $service->save([SeoAnalyticsScopeSettingsService::KEY_EXCLUDE_PAGES_FROM_STATISTICS => '1']);
        $this->assertTrue($service->excludePagesFromStatistics());
    }

    public function test_bool_cast_false_default_anti_pattern_is_not_used(): void
    {
        $src = (string) file_get_contents((string) (new \ReflectionClass(SeoAnalyticsScopeSettingsService::class))->getFileName());
        $this->assertStringNotContainsString('(bool) ($data[', $src);
        $this->assertStringNotContainsString('?? false', $src);
        $this->assertStringContainsString('array_key_exists', $src);
    }

    public function test_general_settings_renders_toggle_and_saves_via_service(): void
    {
        $src = (string) file_get_contents((string) (new \ReflectionClass(SeoSettingsGeneral::class))->getFileName());
        $this->assertStringContainsString('SeoAnalyticsScopeSettingsService', $src);
        $this->assertStringContainsString('KEY_EXCLUDE_PAGES_FROM_STATISTICS', $src);
        $this->assertStringContainsString('statistics_section', $src);
        $this->assertStringContainsString('Toggle::make', $src);
        $this->assertStringContainsString('$analyticsScopeSettings->save', $src);
        $this->assertStringContainsString('$analyticsScopeSettings->getSettings()', $src);
    }

    public function test_scope_uses_canonical_content_type_meta_not_task_post_type(): void
    {
        $src = (string) file_get_contents((string) (new \ReflectionClass(SeoAnalyticsArticleScope::class))->getFileName());
        $this->assertStringContainsString('ArticleContentClassification::META_CONTENT_TYPE', $src);
        $this->assertStringContainsString('ContentType::Page', $src);
        $this->assertStringNotContainsString('$task->post_type', $src);
        $this->assertStringNotContainsString("'post_type'", $src);
        $this->assertSame('content_type', ArticleContentClassification::META_CONTENT_TYPE);
        $this->assertSame('page', ContentType::Page->value);
    }

    public function test_domain_statistics_consumes_analytics_scope(): void
    {
        $src = (string) file_get_contents((string) (new \ReflectionClass(DomainStatisticsReadModel::class))->getFileName());
        $this->assertStringContainsString('SeoAnalyticsArticleScope', $src);
        $this->assertStringContainsString('scopedArticles', $src);
        $this->assertStringContainsString('page_exclusion', $src);
    }

    public function test_eligible_content_scope_hardcodes_post_product_allowlist(): void
    {
        $src = (string) file_get_contents(
            (string) (new \ReflectionClass(EligibleContentScope::class))->getFileName(),
        );
        $this->assertStringNotContainsString('McpEligibleContentScope', $src);
        $this->assertStringNotContainsString('SeoAnalyticsArticleScope', $src);
        $this->assertStringNotContainsString('exclude_pages_from_statistics', $src);
        $this->assertStringContainsString('ContentType::Post', $src);
        $this->assertStringContainsString('ContentType::Product', $src);
    }

    public function test_locale_keys_exist(): void
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

        foreach (['statistics_section', 'exclude_pages_from_statistics', 'exclude_pages_from_statistics_hint'] as $key) {
            $this->assertArrayHasKey($key, $vi['settings_general']);
            $this->assertArrayHasKey($key, $en['settings_general']);
        }
    }
}
