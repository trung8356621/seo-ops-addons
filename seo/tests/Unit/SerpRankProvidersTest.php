<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Content\DataTransfer\SerpRankRequest;
use Omnichannel\Addons\Content\DataTransfer\SerpRankResult;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpProviderConnection;
use Omnichannel\Addons\SearchIntelligence\Providers\Serp\SearchApiProvider;
use Omnichannel\Addons\SearchIntelligence\Providers\Serp\SerpApiProvider;
use Omnichannel\Addons\SearchIntelligence\Providers\Serp\SerpRankProviderRegistry;
use Omnichannel\Addons\SearchIntelligence\Providers\Serp\SerperDevProvider;
use Omnichannel\Addons\SearchIntelligence\Services\SerpTrackedDomainMatcherService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SerpRankProvidersTest extends TestCase
{
    private function connection(string $provider): SeoSerpProviderConnection
    {
        $connection = new SeoSerpProviderConnection;
        $connection->forceFill([
            'id' => 1,
            'provider' => $provider,
            'name' => 'Test',
            'api_key' => 'secret-key',
            'default_country' => 'us',
            'default_language' => 'en',
            'default_device' => 'desktop',
            'result_depth' => 10,
        ]);

        return $connection;
    }

    public function test_registry_resolves_all_providers(): void
    {
        $registry = app(SerpRankProviderRegistry::class);

        $this->assertTrue($registry->has('serper'));
        $this->assertTrue($registry->has('serpapi'));
        $this->assertTrue($registry->has('searchapi'));
        $this->assertSame('serper', $registry->get('serper')->providerKey());
    }

    public function test_registry_unknown_provider_fails_safely(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(SerpRankProviderRegistry::class)->get('unknown');
    }

    public function test_serper_normalizes_organic_results_and_domain_match(): void
    {
        Http::fake([
            'https://google.serper.dev/search' => Http::response([
                'organic' => [
                    ['title' => 'Other', 'link' => 'https://other.com/page', 'position' => 1],
                    ['title' => 'Mine', 'link' => 'https://example.com/a', 'position' => 2, 'snippet' => 'Snippet'],
                ],
            ], 200),
        ]);

        $provider = app(SerperDevProvider::class);
        $result = $provider->search($this->connection('serper'), new SerpRankRequest(
            keyword: 'test keyword',
            trackedDomain: 'example.com',
        ));

        $this->assertSame(SerpRankResult::STATUS_SUCCESS_FOUND, $result->status);
        $this->assertSame(2.0, $result->trackedDomainBestPosition);
        $this->assertSame('https://example.com/a', $result->trackedUrl);
        $this->assertCount(2, $result->organicResults);
    }

    public function test_serper_invalid_key_maps_to_invalid_credentials(): void
    {
        Http::fake([
            'https://google.serper.dev/search' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $result = app(SerperDevProvider::class)->search(
            $this->connection('serper'),
            new SerpRankRequest(keyword: 'x'),
        );

        $this->assertSame(SerpRankResult::STATUS_INVALID_CREDENTIALS, $result->status);
    }

    public function test_serpapi_allintitle_parses_total_results(): void
    {
        Http::fake([
            'https://serpapi.com/search.json*' => Http::response([
                'search_information' => ['total_results' => 12800],
                'organic_results' => [],
            ], 200),
        ]);

        $result = app(SerpApiProvider::class)->searchAllintitle(
            $this->connection('serpapi'),
            new SerpRankRequest(keyword: 'seo audit'),
        );

        $this->assertSame(\Omnichannel\Addons\Content\DataTransfer\SerpAllintitleResult::STATUS_SUCCESS, $result->status);
        $this->assertSame(12800, $result->estimatedResults);
    }

    public function test_serpapi_maps_query_and_normalizes_results(): void
    {
        Http::fake([
            'https://serpapi.com/search.json*' => Http::response([
                'organic_results' => [
                    ['title' => 'A', 'link' => 'https://example.com', 'position' => 1],
                ],
            ], 200),
        ]);

        $result = app(SerpApiProvider::class)->search(
            $this->connection('serpapi'),
            new SerpRankRequest(keyword: 'hello', country: 'us', language: 'en', trackedDomain: 'example.com'),
        );

        $this->assertSame(SerpRankResult::STATUS_SUCCESS_FOUND, $result->status);
        $this->assertSame(1.0, $result->trackedDomainBestPosition);
    }

    public function test_serpapi_error_payload_even_with_http_200(): void
    {
        Http::fake([
            'https://serpapi.com/search.json*' => Http::response([
                'error' => 'Invalid API key',
            ], 200),
        ]);

        $result = app(SerpApiProvider::class)->search(
            $this->connection('serpapi'),
            new SerpRankRequest(keyword: 'hello'),
        );

        $this->assertSame(SerpRankResult::STATUS_INVALID_CREDENTIALS, $result->status);
    }

    public function test_searchapi_rate_limit_maps_correctly(): void
    {
        Http::fake([
            'https://www.searchapi.io/api/v1/search*' => Http::response([
                'error' => 'Rate limit exceeded',
            ], 429),
        ]);

        $result = app(SearchApiProvider::class)->search(
            $this->connection('searchapi'),
            new SerpRankRequest(keyword: 'hello'),
        );

        $this->assertSame(SerpRankResult::STATUS_RATE_LIMITED, $result->status);
    }

    public function test_domain_matcher_finds_subdomain(): void
    {
        $matcher = app(SerpTrackedDomainMatcherService::class);
        $match = $matcher->findBestMatch('example.com', [
            new \Omnichannel\Addons\Content\DataTransfer\SerpOrganicResult(3, 'Shop', 'https://shop.example.com/p', null, null),
        ]);

        $this->assertSame(3.0, $match['position']);
        $this->assertSame('https://shop.example.com/p', $match['url']);
    }
}
