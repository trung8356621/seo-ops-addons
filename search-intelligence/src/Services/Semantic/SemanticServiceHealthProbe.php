<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Probes GET /health/ready and classifies healthy / degraded / down.
 * Does not publish notifications — reporter/monitor owns that boundary.
 */
final class SemanticServiceHealthProbe
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?int $timeoutSeconds = null,
        private readonly ?int $connectTimeoutSeconds = null,
    ) {}

    public function probe(): SemanticServiceHealthSnapshot
    {
        $base = rtrim($this->baseUrl ?? (string) config('semantic.url', 'http://127.0.0.1:8088'), '/');
        $host = $this->safeHost($base);
        $timeout = max(1, $this->timeoutSeconds ?? (int) config('semantic.timeout', 120));
        $connect = max(1, $this->connectTimeoutSeconds ?? (int) config('semantic.connect_timeout', 5));
        // Readiness probe should fail fast on connect; bound total wait modestly.
        $timeout = min($timeout, 30);

        $t0 = hrtime(true);
        try {
            $response = Http::baseUrl($base)
                ->connectTimeout($connect)
                ->timeout($timeout)
                ->acceptJson()
                ->get('/health/ready');
        } catch (ConnectionException $e) {
            $latency = (int) round((hrtime(true) - $t0) / 1e6);
            $message = $e->getMessage();
            $isTimeout = $this->isTimeoutMessage($message);

            return new SemanticServiceHealthSnapshot(
                status: SemanticServiceHealthStatus::Down,
                errorCode: $isTimeout ? 'semantic_timeout' : 'semantic_unavailable',
                reason: $isTimeout
                    ? 'Semantic service readiness probe timed out.'
                    : 'Semantic service is unreachable.',
                baseHost: $host,
                latencyMs: $latency,
            );
        }

        $latency = (int) round((hrtime(true) - $t0) / 1e6);
        $statusCode = $response->status();
        $json = $response->json();
        $payload = is_array($json) ? $json : [];

        if ($response->successful()) {
            if (($payload['ready'] ?? false) === true) {
                return new SemanticServiceHealthSnapshot(
                    status: SemanticServiceHealthStatus::Healthy,
                    errorCode: '',
                    reason: 'Semantic service is ready.',
                    baseHost: $host,
                    httpStatus: $statusCode,
                    payload: $payload,
                    latencyMs: $latency,
                );
            }

            return new SemanticServiceHealthSnapshot(
                status: SemanticServiceHealthStatus::Degraded,
                errorCode: 'semantic_not_ready',
                reason: 'Semantic service responded but is not ready to serve requests.',
                baseHost: $host,
                httpStatus: $statusCode,
                payload: $payload,
                latencyMs: $latency,
            );
        }

        if (in_array($statusCode, [502, 503, 504], true)) {
            // FastAPI /health/ready returns 503 when ready=false with a JSON body.
            if (($payload['ready'] ?? null) === false || array_key_exists('ready', $payload)) {
                return new SemanticServiceHealthSnapshot(
                    status: SemanticServiceHealthStatus::Degraded,
                    errorCode: 'semantic_not_ready',
                    reason: 'Semantic service reported not ready.',
                    baseHost: $host,
                    httpStatus: $statusCode,
                    payload: $payload,
                    latencyMs: $latency,
                );
            }

            return new SemanticServiceHealthSnapshot(
                status: SemanticServiceHealthStatus::Down,
                errorCode: 'semantic_unavailable',
                reason: 'Semantic service returned HTTP '.$statusCode.'.',
                baseHost: $host,
                httpStatus: $statusCode,
                payload: $payload,
                latencyMs: $latency,
            );
        }

        // 4xx on readiness is unexpected but proves the process answered — treat as degraded, not down.
        if ($statusCode >= 400 && $statusCode < 500) {
            return new SemanticServiceHealthSnapshot(
                status: SemanticServiceHealthStatus::Degraded,
                errorCode: 'semantic_ready_http_'.$statusCode,
                reason: 'Semantic readiness endpoint returned HTTP '.$statusCode.'.',
                baseHost: $host,
                httpStatus: $statusCode,
                payload: $payload,
                latencyMs: $latency,
            );
        }

        return new SemanticServiceHealthSnapshot(
            status: SemanticServiceHealthStatus::Down,
            errorCode: 'semantic_unavailable',
            reason: 'Semantic service returned HTTP '.$statusCode.'.',
            baseHost: $host,
            httpStatus: $statusCode,
            payload: $payload,
            latencyMs: $latency,
        );
    }

    private function safeHost(string $baseUrl): ?string
    {
        $parts = parse_url($baseUrl);
        if (! is_array($parts)) {
            return null;
        }
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return null;
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $host.$port;
    }

    private function isTimeoutMessage(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'timed out')
            || str_contains($lower, 'operation timed out')
            || str_contains($lower, 'curl error 28');
    }
}
