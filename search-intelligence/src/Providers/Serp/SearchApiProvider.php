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

final class SearchApiProvider extends AbstractSerpRankProvider
{
    private const API_URL = 'https://www.searchapi.io/api/v1/search';

    public function providerKey(): string
    {
        return SerpProviderKeys::SEARCHAPI;
    }

    public function displayName(): string
    {
        return SerpProviderKeys::label(SerpProviderKeys::SEARCHAPI);
    }

    public function testConnection(SeoSerpProviderConnection $connection): array
    {
        $request = new SerpRankRequest(keyword: 'searchapi connection test', depth: 1);
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
        $query = $this->buildQuery($connection, $request);

        $response = $this->sendRequest($connection, 'GET', self::API_URL, $query, 60);
        $durationMs = (int) ($response['duration_ms'] ?? 0);
        $body = is_array($response['body']) ? $response['body'] : null;

        if ($body !== null && isset($body['error']) && is_string($body['error']) && trim($body['error']) !== '') {
            $status = $this->resolveErrorStatus((int) ($response['status'] ?? 200), $body, (string) ($response['raw'] ?? ''));

            return $this->buildFailureResult($this->providerKey(), $request, $status, $body['error'], $durationMs);
        }

        if (! ($response['ok'] ?? false) || $body === null) {
            $status = $this->resolveErrorStatus(
                (int) ($response['status'] ?? 0),
                $body,
                (string) ($response['raw'] ?? ''),
            );

            return $this->buildFailureResult(
                $this->providerKey(),
                $request,
                $status,
                $this->extractErrorMessage($body, (string) ($response['raw'] ?? '')),
                $durationMs,
            );
        }

        $organic = $this->extractOrganicResults($body);

        return $this->buildSuccessResult(
            $this->providerKey(),
            $request,
            $organic,
            $durationMs,
            [
                'search_parameters' => $body['search_parameters'] ?? null,
                'search_information' => $body['search_information'] ?? null,
            ],
        );
    }

    protected function authenticateRequest(PendingRequest $request, SeoSerpProviderConnection $connection): PendingRequest
    {
        return $request->withToken((string) $connection->api_key);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildQuery(SeoSerpProviderConnection $connection, SerpRankRequest $request): array
    {
        $query = [
            'engine' => 'google',
            'q' => $request->keyword,
            'num' => min(max($request->depth, 1), 100),
        ];

        if (filled($request->country)) {
            $query['gl'] = mb_strtolower((string) $request->country);
        }

        if (filled($request->language)) {
            $query['hl'] = mb_strtolower((string) $request->language);
        }

        if (filled($request->location)) {
            $query['location'] = $request->location;
        }

        if (filled($request->device) && $request->device !== 'all') {
            $query['device'] = $request->device;
        }

        return $query;
    }

    protected function extractOrganicResults(array $body): array
    {
        $items = $body['organic_results'] ?? [];
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

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function extractEstimatedTotalResults(array $metadata): ?int
    {
        $info = $metadata['search_information'] ?? null;
        if (! is_array($info)) {
            return null;
        }

        $total = $info['total_results'] ?? null;
        if (is_numeric($total)) {
            return max(0, (int) $total);
        }

        if (is_string($total)) {
            $digits = preg_replace('/\D+/', '', $total);

            return is_numeric($digits) && $digits !== '' ? (int) $digits : null;
        }

        return null;
    }
}
