<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticInvalidResponseException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticTimeoutException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticTransportException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticUnavailableException;

/**
 * Thin HTTP client for seo-ops-semantic.
 *
 * Knows transport only — not Topic business rules or proposal mapping.
 * No automatic retry on POST (analysis is not idempotent).
 */
final class SemanticAnalyticsClient
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?int $timeoutSeconds = null,
        private readonly ?int $connectTimeoutSeconds = null,
        private readonly ?SemanticServiceHealthReporter $healthReporter = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getJson(string $path, ?string $requestId = null): array
    {
        return $this->send('GET', $path, null, $requestId);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function postJson(string $path, array $body, ?string $requestId = null): array
    {
        return $this->send('POST', $path, $body, $requestId);
    }

    public function delete(string $path, ?string $requestId = null): void
    {
        $this->send('DELETE', $path, null, $requestId, expectBody: false);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(
        string $method,
        string $path,
        ?array $body,
        ?string $requestId,
        bool $expectBody = true,
    ): array {
        $url = $this->url($path);
        $pending = Http::baseUrl($this->resolvedBaseUrl())
            ->connectTimeout($this->resolvedConnectTimeout())
            ->timeout($this->resolvedTimeout())
            ->acceptJson()
            ->asJson();

        if ($requestId !== null && $requestId !== '') {
            $pending = $pending->withHeaders(['X-Request-Id' => $requestId]);
        }

        try {
            $response = match (strtoupper($method)) {
                'GET' => $pending->get($path),
                'POST' => $pending->post($path, $body ?? []),
                'DELETE' => $pending->delete($path),
                default => throw new \InvalidArgumentException('Unsupported HTTP method: '.$method),
            };
        } catch (ConnectionException $e) {
            if ($this->isTimeoutMessage($e->getMessage())) {
                $timeout = SemanticTimeoutException::requestTimedOut($e->getMessage(), $e);
                $this->healthReporter?->reportTransportFailure($timeout);
                throw $timeout;
            }

            $unavailable = SemanticUnavailableException::connectionFailed($e->getMessage(), $e);
            $this->healthReporter?->reportTransportFailure($unavailable);
            throw $unavailable;
        }

        if ($response->successful()) {
            if (! $expectBody || $response->status() === 204) {
                $this->healthReporter?->reportReachable();

                return [];
            }

            $json = $response->json();
            if (! is_array($json)) {
                throw SemanticInvalidResponseException::invalidJson('decoded value is not an object');
            }

            /** @var array<string, mixed> $json */
            $this->healthReporter?->reportReachable();

            return $json;
        }

        if (in_array($response->status(), [502, 503, 504], true)) {
            $unavailable = SemanticUnavailableException::connectionFailed(
                'HTTP '.$response->status().' from '.$url,
            );
            $this->healthReporter?->reportTransportFailure($unavailable);
            throw $unavailable;
        }

        // 4xx (including 422) means the service answered — not an availability outage.
        try {
            $response->throw();
        } catch (RequestException $e) {
            throw SemanticTransportException::httpError(
                $response->status(),
                $response->body(),
            );
        }

        throw SemanticTransportException::httpError($response->status(), $response->body());
    }

    private function url(string $path): string
    {
        return $this->resolvedBaseUrl().'/'.ltrim($path, '/');
    }

    private function resolvedBaseUrl(): string
    {
        $configured = $this->baseUrl ?? (string) config('semantic.url', 'http://127.0.0.1:8088');

        return rtrim($configured, '/');
    }

    private function resolvedTimeout(): int
    {
        $timeout = $this->timeoutSeconds ?? (int) config('semantic.timeout', 120);

        return max(1, $timeout);
    }

    private function resolvedConnectTimeout(): int
    {
        $timeout = $this->connectTimeoutSeconds ?? (int) config('semantic.connect_timeout', 5);

        return max(1, $timeout);
    }

    private function isTimeoutMessage(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'timed out')
            || str_contains($lower, 'operation timed out')
            || str_contains($lower, 'curl error 28');
    }
}
