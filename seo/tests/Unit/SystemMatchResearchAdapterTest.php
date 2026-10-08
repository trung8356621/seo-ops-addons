<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\MatchRules\GlobalMatchRuleRegistry;
use Omnichannel\Addons\Seo\Services\MatchRules\SystemMatchResearchAdapter;
use Omnichannel\Addons\Seo\Services\SeoKeywordSettingsService;
use PHPUnit\Framework\TestCase;

final class SystemMatchResearchAdapterTest extends TestCase
{
    public function test_projects_legacy_keys_without_delete_and_preserves_cta(): void
    {
        $adapter = new SystemMatchResearchAdapter(
            new GlobalMatchRuleRegistry,
            SeoKeywordSettingsService::withDefaults(),
        );
        $resources = $adapter->resources();
        self::assertNotSame([], $resources);

        $keys = array_map(static fn ($r) => $r->key, $resources);
        self::assertContains('system.cta_blacklist', $keys);
        self::assertContains('system.link_phrase_stopwords', $keys);

        foreach ($resources as $resource) {
            self::assertFalse($resource->deletable);
            self::assertSame('system.', substr($resource->key, 0, 7));
            self::assertFalse($resource->capabilities->canTag);
            self::assertFalse($resource->capabilities->canMatch);
        }

        $cta = $adapter->find('system.cta_blacklist');
        self::assertNotNull($cta);
        self::assertSame('cta_blacklist', $cta->payload['legacy_key']);
        self::assertContains('liên hệ ngay', $cta->payload['terms']);

        $stopwords = $adapter->find('system.link_phrase_stopwords');
        self::assertNotNull($stopwords);
        self::assertFalse($stopwords->capabilities->canTag);
    }

    public function test_seo_keyword_settings_service_behavior_unchanged(): void
    {
        $service = SeoKeywordSettingsService::withDefaults();
        $settings = $service->getSettings();
        self::assertArrayHasKey('cta_blacklist', $settings);
        self::assertContains('liên hệ ngay', $settings['cta_blacklist']);
        self::assertSame($settings, $service->globalMatchRules());
        self::assertSame($settings['cta_blacklist'], $service->getCtaBlacklist());
    }
}
