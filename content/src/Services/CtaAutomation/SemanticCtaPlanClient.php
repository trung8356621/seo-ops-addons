<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\CtaAutomation;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Calls the existing semantic service CTA planner. No local heuristic fallback.
 */
final class SemanticCtaPlanClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function plan(array $payload): array
    {
        if (config('semantic.enabled') !== true) {
            throw new RuntimeException('semantic_unavailable');
        }
        $base = rtrim((string) config('semantic.url', ''), '/');
        if ($base === '') {
            throw new RuntimeException('semantic_unavailable');
        }

        $path = '/v1/cta/plan';
        try {
            $response = Http::baseUrl($base)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('semantic.timeout', 30))
                ->post($path, $payload);
        } catch (\Throwable $exception) {
            $this->logFailure(0, $path, $exception::class, null);
            throw new RuntimeException('semantic_unavailable', 0, $exception);
        }

        if (! $response->successful()) {
            $status = $response->status();
            $errorType = $this->errorType($response);
            $correlationId = $this->correlationId($response);
            $this->logFailure($status, $path, $errorType, $correlationId);
            $detail = 'semantic_plan_failed HTTP '.$status.' '.$path.' '.$errorType;
            if ($correlationId !== null) {
                $detail .= ' id='.$correlationId;
            }
            throw new RuntimeException($detail);
        }
        $json = $response->json();
        if (! is_array($json) || ! isset($json['placements']) || ! is_array($json['placements'])) {
            throw new RuntimeException('semantic_invalid_plan');
        }

        return $json;
    }

    private function logFailure(int $status, string $path, string $errorType, ?string $correlationId): void
    {
        Log::warning('cta.semantic_plan_failed', [
            'http_status' => $status,
            'path' => $path,
            'error_type' => $errorType,
            'correlation_id' => $correlationId,
        ]);
    }

    private function errorType(Response $response): string
    {
        $status = $response->status();
        if ($status === 404) {
            return 'not_found';
        }
        if ($status === 422) {
            return 'validation_error';
        }
        if ($status >= 500) {
            $detail = $response->json('detail');
            if (is_string($detail) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,80}$/', $detail) === 1) {
                return $detail;
            }

            return 'server_error';
        }

        return 'http_error';
    }

    private function correlationId(Response $response): ?string
    {
        foreach (['x-request-id', 'x-correlation-id'] as $header) {
            $value = $response->header($header);
            if (is_string($value) && preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $value) === 1) {
                return $value;
            }
        }

        return null;
    }
}
