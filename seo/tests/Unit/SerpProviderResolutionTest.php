<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Contracts\SerpIntelligenceProviderRegistry;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpQueryRequest;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Providers\FakeLocalSerpProvider;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Providers\ManualImportSerpProvider;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpProviderResolver;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpResultClassifier;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpUrlNormalizationService;
use PHPUnit\Framework\TestCase;

final class SerpProviderResolutionTest extends TestCase
{
    private SerpProviderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = new SerpIntelligenceProviderRegistry;
        $registry->register(new ManualImportSerpProvider(new SerpUrlNormalizationService, new SerpResultClassifier));
        $registry->register(new FakeLocalSerpProvider);

        $this->resolver = new SerpProviderResolver($registry);
    }

    public function test_manual_import_provider_key(): void
    {
        $provider = new ManualImportSerpProvider(new SerpUrlNormalizationService, new SerpResultClassifier);
        self::assertSame('manual_import', $provider->key());
    }

    public function test_fake_local_provider_returns_synthetic_results(): void
    {
        $provider = new FakeLocalSerpProvider;
        $request = $this->requestForProvider('fake_local');
        $result = $provider->collect($request);

        self::assertTrue($result->success);
        self::assertCount(10, $result->results);
        self::assertSame('fake_local', $result->providerKey);
    }

    public function test_resolver_fail_closed_on_unknown_provider(): void
    {
        $resolved = $this->resolver->resolve(
            $this->requestForProvider('does_not_exist'),
            ['enabled_providers' => ['manual_import', 'fake_local']],
        );

        self::assertNull($resolved['provider']);
        self::assertSame('serp_provider.not_registered', $resolved['error_code']);
    }

    public function test_resolver_fail_closed_on_disabled_provider(): void
    {
        $resolved = $this->resolver->resolve(
            $this->requestForProvider('fake_local'),
            ['enabled_providers' => ['manual_import']],
        );

        self::assertNull($resolved['provider']);
        self::assertSame('serp_provider.disabled', $resolved['error_code']);
    }

    public function test_resolver_does_not_silent_fallback_to_other_provider(): void
    {
        $resolved = $this->resolver->resolve(
            $this->requestForProvider('unknown_provider_key'),
            ['enabled_providers' => ['manual_import', 'fake_local']],
        );

        self::assertNull($resolved['provider']);
        self::assertSame('serp_provider.not_registered', $resolved['error_code']);
        self::assertSame('unknown_provider_key', $resolved['metadata']['provider_key'] ?? null);
    }

    public function test_resolver_rejects_missing_provider_key(): void
    {
        $request = new SerpQueryRequest(
            tenantRef: null,
            siteRef: null,
            query: 'seo',
            displayQuery: 'seo',
            normalizedQuery: 'seo',
            language: 'vi',
            country: 'VN',
            location: null,
            device: 'desktop',
            searchEngine: 'google',
            providerKey: '',
        );

        $resolved = $this->resolver->resolve($request);

        self::assertNull($resolved['provider']);
        self::assertSame('serp_provider.not_configured', $resolved['error_code']);
    }

    private function requestForProvider(string $providerKey): SerpQueryRequest
    {
        return new SerpQueryRequest(
            tenantRef: null,
            siteRef: null,
            query: 'dich vu seo',
            displayQuery: 'dich vu seo',
            normalizedQuery: 'dich vu seo',
            language: 'vi',
            country: 'VN',
            location: null,
            device: 'desktop',
            searchEngine: 'google',
            providerKey: $providerKey,
            options: $providerKey === 'manual_import' ? ['import_payload' => '[{"url":"https://example.com/a"}]'] : [],
        );
    }
}
