<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Application\Publishing;

use Carbon\Carbon;
use Throwable;

/**
 * Single place to classify publish failures as retryable vs permanent.
 */
final class PublishFailureClassifier
{
    private const RETRYABLE_HTTP = [408, 429, 500, 502, 503, 504];

    private const PERMANENT_HTTP = [400, 401, 403, 404, 409, 422];

    /**
     * @param  array{http_status?: int|null, retry_after?: int|string|null, code?: string|null, message?: string|null}  $context
     */
    public function classify(?Throwable $exception = null, array $context = []): PublishFailureClassification
    {
        $http = isset($context['http_status']) ? (int) $context['http_status'] : null;
        if ($http !== null && $http <= 0) {
            $http = null;
        }

        $rawMessage = trim((string) ($context['message'] ?? $exception?->getMessage() ?? ''));
        $rawCode = trim((string) ($context['code'] ?? ''));
        $sanitized = $this->sanitizeMessage($rawMessage !== '' ? $rawMessage : ($rawCode !== '' ? $rawCode : 'Publish failed.'));

        if ($http !== null && in_array($http, self::PERMANENT_HTTP, true)) {
            return new PublishFailureClassification(
                retryable: false,
                code: $rawCode !== '' ? $rawCode : 'http_'.$http,
                message: $sanitized,
                httpStatus: $http,
            );
        }

        if ($http === 429) {
            return new PublishFailureClassification(
                retryable: true,
                code: $rawCode !== '' ? $rawCode : 'http_429',
                message: $sanitized,
                httpStatus: 429,
                retryAfter: $this->resolveRetryAfter($context['retry_after'] ?? null),
            );
        }

        if ($http !== null && in_array($http, self::RETRYABLE_HTTP, true)) {
            return new PublishFailureClassification(
                retryable: true,
                code: $rawCode !== '' ? $rawCode : 'http_'.$http,
                message: $sanitized,
                httpStatus: $http,
            );
        }

        $hay = strtolower($sanitized.' '.$rawCode);
        if ($this->looksPermanent($hay)) {
            return new PublishFailureClassification(
                retryable: false,
                code: $rawCode !== '' ? $rawCode : 'permanent_error',
                message: $sanitized,
                httpStatus: $http,
            );
        }

        if ($this->looksRetryable($hay) || in_array($rawCode, [
            'lease_expired',
            'worker_killed',
            'WP_PUBLISHED_POST_NOT_FOUND',
        ], true)) {
            return new PublishFailureClassification(
                retryable: true,
                code: $rawCode !== '' ? $rawCode : 'transient_error',
                message: $sanitized,
                httpStatus: $http,
            );
        }

        // Unknown → treat as retryable once (safer than silent stuck), still capped by max attempts.
        return new PublishFailureClassification(
            retryable: true,
            code: $rawCode !== '' ? $rawCode : 'unknown_error',
            message: $sanitized,
            httpStatus: $http,
        );
    }

    public function sanitizeMessage(string $message): string
    {
        $message = preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/(authorization|token|password|secret|api[_-]?key)\s*[:=]\s*\S+/i', '$1=[redacted]', $message) ?? $message;
        $message = preg_replace('/https?:\/\/[^\s]+@(?:[^\s\/]+)/i', '[redacted-url]', $message) ?? $message;

        return mb_substr(trim($message), 0, 500);
    }

    private function looksPermanent(string $hay): bool
    {
        foreach ([
            'authentication',
            'unauthorized',
            'forbidden',
            'permission',
            'capability',
            'invalid payload',
            'payload contract',
            'validation',
            'domain/connection',
            'connection không hợp lệ',
            'mapping sai',
            'duplicate',
            'conflict',
            'not configured',
            'missing article',
            'plugin thiếu',
        ] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function looksRetryable(string $hay): bool
    {
        foreach ([
            'timeout',
            'timed out',
            'connection reset',
            'connection refused',
            'could not resolve',
            'dns',
            'curl error',
            'ssl',
            'temporarily unavailable',
            'lease expired',
            'worker',
            '503',
            '502',
            '504',
            '429',
            '408',
        ] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function resolveRetryAfter(mixed $raw): ?Carbon
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            $seconds = (int) $raw;

            return $seconds > 0 ? now()->addSeconds($seconds) : null;
        }

        if (is_string($raw)) {
            try {
                $parsed = Carbon::parse($raw);
                if ($parsed->isFuture()) {
                    return $parsed;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
