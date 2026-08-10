<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Enums\ApiConnectionType;
use Omnichannel\Addons\Seo\Enums\SeoProviderCapabilityKey;
use Omnichannel\Addons\SearchIntelligence\Services\SeoProviderCapabilityResolver;
use Omnichannel\Addons\SearchIntelligence\Services\SeoProviderRegistry;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Tests\TestCase;

final class SeoProviderRegistryTest extends TestCase
{
    public function test_provider_keys_are_unique(): void
    {
        $registry = app(SeoProviderRegistry::class);
        $keys = $registry->keys();

        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    public function test_ai_and_seo_connection_types(): void
    {
        $registry = app(SeoProviderRegistry::class);

        $this->assertSame(ApiConnectionType::Ai, $registry->connectionTypeFor(ApiConnectionProviders::GEMINI));
        $this->assertSame(ApiConnectionType::Seo, $registry->connectionTypeFor(ApiConnectionProviders::SERPAPI));
        $this->assertSame(ApiConnectionType::Seo, $registry->connectionTypeFor(ApiConnectionProviders::KEYWORDS_EVERYWHERE));
    }

    public function test_keywords_everywhere_has_no_rank_capability(): void
    {
        $definition = app(SeoProviderRegistry::class)->get(ApiConnectionProviders::KEYWORDS_EVERYWHERE);

        $this->assertFalse($definition->isCapabilitySupported(SeoProviderCapabilityKey::RankTracking));
        $this->assertFalse($definition->isCapabilityImplemented(SeoProviderCapabilityKey::SearchVolume));
        $this->assertTrue($definition->isCapabilitySupported(SeoProviderCapabilityKey::SearchVolume));
    }

    public function test_se_ranking_is_not_raw_serp_provider(): void
    {
        $registry = app(SeoProviderRegistry::class);

        $this->assertFalse($registry->isRawSerpProvider(ApiConnectionProviders::SE_RANKING));
        $this->assertFalse($registry->isRankCompatibleProvider(ApiConnectionProviders::SE_RANKING));
    }

    public function test_serper_allintitle_not_supported_by_contract(): void
    {
        $definition = app(SeoProviderRegistry::class)->get(ApiConnectionProviders::SERPER);

        $this->assertFalse($definition->isCapabilitySupported(SeoProviderCapabilityKey::Allintitle));
        $this->assertFalse($definition->isCapabilityImplemented(SeoProviderCapabilityKey::Allintitle));
    }

    public function test_capability_states_do_not_conflate_supported_and_implemented(): void
    {
        $resolver = app(SeoProviderCapabilityResolver::class);
        $state = $resolver->resolve(1, ApiConnectionProviders::KEYWORDS_EVERYWHERE, SeoProviderCapabilityKey::SearchVolume);

        $this->assertTrue($state->supported);
        $this->assertFalse($state->implemented);
        $this->assertFalse($state->configured);
        $this->assertFalse($state->available);
        $this->assertSame('not_implemented', $state->reason);
    }

    public function test_gsc_source_key_mapping(): void
    {
        $registry = app(SeoProviderRegistry::class);

        $this->assertSame('gsc', $registry->sourceKeyFor(ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE));
        $this->assertSame(ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE, $registry->resolveProviderFromSource('gsc'));
    }
}
