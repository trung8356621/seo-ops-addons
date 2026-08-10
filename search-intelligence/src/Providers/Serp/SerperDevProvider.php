<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Providers\Serp;

use Omnichannel\Addons\Content\DataTransfer\SerpOrganicResult;
use Omnichannel\Addons\Content\DataTransfer\SerpProviderUsage;
use Omnichannel\Addons\Content\DataTransfer\SerpRankRequest;
use Omnichannel\Addons\Content\DataTransfer\SerpRankResult;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpProviderConnection;
use Omnichannel\Addons\SearchIntelligence\Support\SerpProviderKeys;
use Illuminate\Http\Client\PendingRequest;

final class SerperDevProvider extends AbstractSerpRankProvider
{
    private const API_URL = 'https://google.serper.dev/search';

    public function providerKey(): string
    {
        return SerpProviderKeys::SERPER;
    }

    public function displayName(): string
    {
        return SerpProviderKeys::label(SerpProviderKeys::SERPER);
    }

    public function supportsAllintitle(): bool
    {
        return false;
    }

    public function testConnection(SeoSerpProviderConnection $connection): array
    {
        $request = new SerpRankRequest(keyword: 'serper connection test', depth: 1);
        $result = $this->search($connection, $request);

        $ok = in_array($result->status, [
            SerpRankResult::STATUS_SUCCESS_FOUND,
            SerpRankResult::STATUS_SUCCESS_NOT_FOUND,
        ], true);

        return [
            'ok' => $ok,
            'message' => $ok
                ? __('seo-content-ai::filament.api_connections.test_success')
                : ($result->errorMessage ?? __('seo-content-ai::filament.api_connections.test_failed')),
            'usage' => $this->getUsageOrCredits($connection),
        ];
    }

    public function search(SeoSerpProviderConnection $connection, SerpRankRequest $request): SerpRankResult
    {
        $request = $this->resolveDefaults($connection, $request);
        $payload = $this->buildPayload($request);

        $response = $this->sendRequest($connection, 'POST', self::API_URL, $payload, 45);
        $durationMs = (int) ($response['duration_ms'] ?? 0);

        if (! ($response['ok'] ?? false) || ! is_array($response['body'])) {
            $status = $this->resolveErrorStatus(
                (int) ($response['status'] ?? 0),
                is_array($response['body']) ? $response['body'] : null,
                (string) ($response['raw'] ?? ''),
            );

            return $this->buildFailureResult(
                $this->providerKey(),
                $request,
                $status,
                $this->extractErrorMessage(is_array($response['body']) ? $response['body'] : null, (string) ($response['raw'] ?? '')),
                $durationMs,
            );
        }

        $organic = $this->extractOrganicResults($response['body']);

        return $this->buildSuccessResult(
            $this->providerKey(),
            $request,
            $organic,
            $durationMs,
            ['search_parameters' => $response['body']['searchParameters'] ?? null],
        );
    }

    protected function authenticateRequest(PendingRequest $request, SeoSerpProviderConnection $connection): PendingRequest
    {
        return $request
            ->withHeaders([
                'X-API-KEY' => (string) $connection->api_key,
                'Content-Type' => 'application/json',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(SerpRankRequest $request): array
    {
        $payload = [
            'q' => $request->keyword,
            'num' => min(max($request->depth, 1), 100),
        ];

        if (filled($request->country)) {
            $payload['gl'] = mb_strtolower((string) $request->country);
        }

        if (filled($request->language)) {
            $payload['hl'] = mb_strtolower((string) $request->language);
        }

        if (filled($request->location)) {
            $payload['location'] = $request->location;
        }

        return $payload;
    }

    protected function extractOrganicResults(array $body): array
    {
        $items = $body['organic'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        $results = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $results[] = SerpOrganicResult::fromArray($item, $index + 1);
        }

        return $results;
    }
}
