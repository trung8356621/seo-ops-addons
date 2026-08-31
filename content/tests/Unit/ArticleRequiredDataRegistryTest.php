<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleRequiredDataRegistry;
use Omnichannel\Addons\SiteSync\Services\Preflight\SiteSyncPreflightService;
use PHPUnit\Framework\TestCase;

final class ArticleRequiredDataRegistryTest extends TestCase
{
    public function test_registry_includes_structural_fields_only_once(): void
    {
        $keys = ArticleRequiredDataRegistry::keys();

        self::assertSame($keys, array_values(array_unique($keys)));
        self::assertContains('slug', $keys);
        self::assertContains('permalink', $keys);
        self::assertContains('content_type', $keys);
        self::assertContains('wp_post_type', $keys);
        self::assertContains('title', $keys);
        self::assertContains('source_id', $keys);
        self::assertContains('status', $keys);
    }

    public function test_seo_optional_fields_are_explicitly_not_required(): void
    {
        $notRequired = ArticleRequiredDataRegistry::explicitlyNotRequired();
        $keys = ArticleRequiredDataRegistry::keys();

        foreach (['meta_title', 'meta_description', 'focus_keyword', 'seo_score', 'featured_image', 'wp_entity', 'published_at', 'language'] as $key) {
            self::assertContains($key, $notRequired);
            self::assertNotContains($key, $keys);
        }
    }

    public function test_permalink_checks_wp_permalink_meta_not_derived_url(): void
    {
        $field = ArticleRequiredDataRegistry::get('permalink');
        self::assertNotNull($field);
        self::assertSame('meta', $field['storage']);
        self::assertSame('wp_permalink', $field['meta_key']);
    }

    public function test_slug_checks_articles_column_independently(): void
    {
        $field = ArticleRequiredDataRegistry::get('slug');
        self::assertNotNull($field);
        self::assertSame('column', $field['storage']);
        self::assertSame('slug', $field['column']);
        self::assertStringContainsString('independent of permalink', $field['how_to_check']);
    }

    public function test_severity_thresholds(): void
    {
        self::assertSame(ArticleRequiredDataRegistry::SEVERITY_GREEN, ArticleRequiredDataRegistry::severityForMissing(0));
        self::assertSame(ArticleRequiredDataRegistry::SEVERITY_YELLOW, ArticleRequiredDataRegistry::severityForMissing(1));
        self::assertSame(ArticleRequiredDataRegistry::SEVERITY_YELLOW, ArticleRequiredDataRegistry::severityForMissing(500));
        self::assertSame(ArticleRequiredDataRegistry::SEVERITY_RED, ArticleRequiredDataRegistry::severityForMissing(501));
    }

    public function test_preflight_recommendation_constants(): void
    {
        self::assertSame('full_sync', SiteSyncPreflightService::RECOMMEND_FULL);
        self::assertSame('normal_sync', SiteSyncPreflightService::RECOMMEND_NORMAL);
        self::assertSame('synced', SiteSyncPreflightService::RECOMMEND_SYNCED);

        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/site-sync/src/Services/Preflight/SiteSyncPreflightService.php',
        );
        self::assertStringContainsString('MISSING_YELLOW_MAX', $src);
        self::assertStringContainsString('Khuyến nghị: Đồng bộ toàn bộ', $src);
        self::assertStringContainsString('Dữ liệu đang đồng bộ', $src);
    }
}
