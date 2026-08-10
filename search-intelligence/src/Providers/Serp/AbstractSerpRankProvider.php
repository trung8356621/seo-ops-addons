<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Providers\Serp;

use Omnichannel\Addons\Seo\Contracts\SerpRankProviderInterface;
use Omnichannel\Addons\Content\DataTransfer\SerpAllintitleResult;
use Omnichannel\Addons\Content\DataTransfer\SerpOrganicResult;
use Omnichannel\Addons\Content\DataTransfer\SerpProviderUsage;
use Omnichannel\Addons\Content\DataTransfer\SerpRankRequest;
use Omnichannel\Addons\Content\DataTransfer\SerpRankResult;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpProviderConnection;
use Omnichannel\Addons\SearchIntelligence\Services\SerpTrackedDomainMatcherService;
use Omnichannel\Addons\Seo\Support\SerpAllintitleQuery;
use Omnichannel\Addons\SearchIntelligence\Support\SerpProviderKeys;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class AbstractSerpRankProvider implements SerpRankProviderInterface
{
    public function __construct(
        protected readonly SerpTrackedDomainMatcherService $domainMatcher,
    ) {}

    public function supportsRankCheck(): bool
    {
        return true;
    }

    public function supportsAllintitle(): bool
    {
        return true;
    }

    public function supportsSearchVolume(): bool
    {
        return false;
    }

    public function searchAllintitle(SeoSerpProviderConnection $connection, SerpRankRequest $request): SerpAllintitleResult
    {
        if (! $this->supportsAllintitle()) {
            return new SerpAllintitleResult(
                provider: $this->providerKey(),
                keyword: $request->keyword,
                estimatedResults: null,
                status: SerpAllintitleResult::STATUS_NOT_SUPPORTED,
                errorMessage: __('seo-content-ai::filament.performance_hub.metric_not_supported'),
            );
        }

        $request = $this->resolveDefaults($connection, $request);
        $allintitleRequest = new SerpRankRequest(
            keyword: SerpAllintitleQuery::build($request->keyword),
            country: $request->country,
            language: $request->language,
            location: $request->location,
            device: $request->device,
            depth: 1,
            trackedDomain: null,
        );

        $result = $this->search($connection, $allintitleRequest);
        $durationMs = $result->durationMs;

        if (! in_array($result->status, [
            SerpRankResult::STATUS_SUCCESS_FOUND,
            SerpRankResult::STATUS_SUCCESS_NOT_FOUND,
        ], true)) {
            return new SerpAllintitleResult(
                provider: $this->providerKey(),
                keyword: $request->keyword,
                estimatedResults: null,
                status: SerpAllintitleResult::STATUS_FAILED,
                errorMessage: $result->errorMessage,
                durationMs: $durationMs,
                metadata: $result->metadata,
            );
        }

        $estimated = $this->extractEstimatedTotalResults($result->metadata);
        if ($estimated === null) {
            return new SerpAllintitleResult(
                provider: $this->providerKey(),
                keyword: $request->keyword,
                estimatedResults: null,
                status: SerpAllintitleResult::STATUS_NOT_SUPPORTED,
                errorMessage: __('seo-content-ai::filament.performance_hub.allintitle_total_unavailable'),
                durationMs: $durationMs,
                metadata: $result->metadata,
            );
        }

        return new SerpAllintitleResult(
            provider: $this->providerKey(),
            keyword: $request->keyword,
            estimatedResults: $estimated,
            status: $estimated > 0
                ? SerpAllintitleResult::STATUS_SUCCESS
                : SerpAllintitleResult::STATUS_NOT_FOUND,
            durationMs: $durationMs,
            metadata: $result->metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function extractEstimatedTotalResults(array $metadata): ?int
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, body: array<string, mixed>|null, raw: string}
     */
    protected function sendRequest(
        SeoSerpProviderConnection $connection,
        string $method,
        string $url,
        array $payload = [],
        int $timeout = 30,
    ): array {
        $started = microtime(true);

        try {
            $request = Http::timeout($timeout)->acceptJson();

            $request = $this->authenticateRequest($request, $connection);

            $response = match (strtoupper($method)) {
                'GET' => $request->get($url, $payload),
                default => $request->post($url, $payload),
            };

            $json = $response->json();
            $body = is_array($json) ? $json : null;

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'body' => $body,
                'raw' => $response->body(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => null,
                'raw' => $exception->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }
    }

    abstract protected function authenticateRequest(\Illuminate\Http\Client\PendingRequest $request, SeoSerpProviderConnection $connection): \Illuminate\Http\Client\PendingRequest;

    /**
     * @param  array<string, mixed>  $body
     * @return list<SerpOrganicResult>
     */
    abstract protected function extractOrganicResults(array $body): array;

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function resolveErrorStatus(int $httpStatus, ?array $body, string $raw): string
    {
        $message = strtolower($this->extractErrorMessage($body, $raw));

        if (in_array($httpStatus, [401, 403], true) || str_contains($message, 'invalid api key') || str_contains($message, 'unauthorized')) {
            return SerpRankResult::STATUS_INVALID_CREDENTIALS;
        }

        if ($httpStatus === 429 || str_contains($message, 'rate limit')) {
            return SerpRankResult::STATUS_RATE_LIMITED;
        }

        if (str_contains($message, 'quota') || str_contains($message, 'credit') || str_contains($message, 'billing')) {
            return SerpRankResult::STATUS_QUOTA_EXHAUSTED;
        }

        if ($httpStatus >= 500 || $httpStatus === 0) {
            return SerpRankResult::STATUS_PROVIDER_UNAVAILABLE;
        }

        return SerpRankResult::STATUS_MALFORMED_RESPONSE;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function extractErrorMessage(?array $body, string $raw): string
    {
        if ($body !== null) {
            foreach (['error', 'message', 'status_message', 'detail'] as $key) {
                if (isset($body[$key]) && is_string($body[$key]) && trim($body[$key]) !== '') {
                    return (string) $body[$key];
                }
            }
        }

        return $raw;
    }

    protected function sanitizeError(string $message): string
    {
        $message = Str::limit(trim($message), 240, '');

        return Str::replaceMatches('/(password|api[_ -]?key|secret|token|x-api-key)\s*[:=]\s*\S+/i', '$1=[redacted]', $message);
    }

    /**
     * @param  list<SerpOrganicResult>  $organic
     */
    protected function buildSuccessResult(
        string $providerKey,
        SerpRankRequest $request,
        array $organic,
        int $durationMs,
        array $metadata = [],
    ): SerpRankResult {
        $match = $this->domainMatcher->findBestMatch($request->trackedDomain, $organic);

        return new SerpRankResult(
            provider: $providerKey,
            keyword: $request->keyword,
            checkedAt: now(),
            country: $request->country,
            language: $request->language,
            location: $request->location,
            device: $request->device,
            organicResults: $organic,
            trackedDomainBestPosition: $match['position'],
            trackedUrl: $match['url'],
            resultCount: count($organic),
            status: $match['position'] !== null
                ? SerpRankResult::STATUS_SUCCESS_FOUND
                : SerpRankResult::STATUS_SUCCESS_NOT_FOUND,
            errorMessage: null,
            durationMs: $durationMs,
            metadata: $metadata,
        );
    }

    protected function buildFailureResult(
        string $providerKey,
        SerpRankRequest $request,
        string $status,
        ?string $errorMessage,
        int $durationMs,
        array $metadata = [],
    ): SerpRankResult {
        return new SerpRankResult(
            provider: $providerKey,
            keyword: $request->keyword,
            checkedAt: now(),
            country: $request->country,
            language: $request->language,
            location: $request->location,
            device: $request->device,
            organicResults: [],
            trackedDomainBestPosition: null,
            trackedUrl: null,
            resultCount: 0,
            status: $status,
            errorMessage: $errorMessage !== null ? $this->sanitizeError($errorMessage) : null,
            durationMs: $durationMs,
            metadata: $metadata,
        );
    }

    protected function resolveDefaults(SeoSerpProviderConnection $connection, SerpRankRequest $request): SerpRankRequest
    {
        return new SerpRankRequest(
            keyword: $request->keyword,
            country: $request->country ?: $connection->default_country,
            language: $request->language ?: $connection->default_language,
            location: $request->location ?: $connection->default_location,
            device: $request->device ?: ($connection->default_device ?: 'desktop'),
            depth: $request->depth > 0 ? $request->depth : (int) ($connection->result_depth ?: 100),
            trackedDomain: $request->trackedDomain,
        );
    }

    public function getUsageOrCredits(SeoSerpProviderConnection $connection): ?SerpProviderUsage
    {
        return null;
    }
}
