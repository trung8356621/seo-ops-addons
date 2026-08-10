<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Contracts\GscIntelligenceProviderRegistry;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Data\GscSearchAnalyticsRequest;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscFactHashService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscImportPreviewService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPageNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscProviderResolver;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscQueryNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Providers\FakeLocalGscProvider;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Providers\ManualImportGscProvider;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpUrlNormalizationService;
use PHPUnit\Framework\TestCase;

final class GscProviderResolutionTest extends TestCase
{
    private GscProviderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $importPreview = new GscImportPreviewService(
            new GscQueryNormalizationService(new KeywordNormalizationService),
            new GscPageNormalizationService(new SerpUrlNormalizationService),
            new GscFactHashService,
        );

        $registry = new GscIntelligenceProviderRegistry;
        $registry->register(new ManualImportGscProvider($importPreview));
        $registry->register(new FakeLocalGscProvider(new GscFactHashService));

        $this->resolver = new GscProviderResolver($registry);
    }

    public function test_manual_import_provider_key(): void
    {
        $provider = new ManualImportGscProvider(new GscImportPreviewService(
            new GscQueryNormalizationService(new KeywordNormalizationService),
            new GscPageNormalizationService(new SerpUrlNormalizationService),
            new GscFactHashService,
        ));

        self::assertSame('manual_import', $provider->key());
    }

    public function test_resolver_fail_closed_on_unknown_provider(): void
    {
        $resolved = $this->resolver->resolve(
            $this->request('missing_provider'),
            ['enabled_providers' => ['manual_import', 'fake_local']],
        );

        self::assertNull($resolved['provider']);
        self::assertSame('gsc_provider.not_registered', $resolved['error_code']);
    }

    public function test_resolver_fail_closed_on_disabled_provider(): void
    {
        $resolved = $this->resolver->resolve(
            $this->request('fake_local'),
            ['enabled_providers' => ['manual_import']],
        );

        self::assertNull($resolved['provider']);
        self::assertSame('gsc_provider.disabled', $resolved['error_code']);
    }

    public function test_resolver_does_not_silent_fallback(): void
    {
        $resolved = $this->resolver->resolve(
            $this->request('unknown_key'),
            ['enabled_providers' => ['manual_import', 'fake_local']],
        );

        self::assertNull($resolved['provider']);
        self::assertSame('gsc_provider.not_registered', $resolved['error_code']);
        self::assertSame('unknown_key', $resolved['metadata']['provider_key'] ?? null);
    }

    public function test_resolver_accepts_manual_import_when_enabled(): void
    {
        $resolved = $this->resolver->resolve(
            $this->request('manual_import'),
            ['enabled_providers' => ['manual_import']],
        );

        self::assertNotNull($resolved['provider']);
        self::assertNull($resolved['error_code']);
        self::assertSame('manual_import', $resolved['metadata']['provider_key'] ?? null);
    }

    private function request(string $providerKey): GscSearchAnalyticsRequest
    {
        return new GscSearchAnalyticsRequest(
            tenantRef: null,
            siteRef: '1',
            propertyRef: 'gscp_test',
            startDate: '2026-07-01',
            endDate: '2026-07-07',
            providerKey: $providerKey,
            options: $providerKey === 'manual_import' ? ['import_payload' => "date,query,page,country,device,search_appearance,clicks,impressions,ctr,position\n2026-07-01,seo,https://example.test/a,vnm,desktop,,1,10,0.1,5"] : [],
        );
    }
}
