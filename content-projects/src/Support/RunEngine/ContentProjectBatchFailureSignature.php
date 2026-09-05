<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\RunEngine;

use Omnichannel\Addons\Content\Support\RunEngine\ArticleExecutionResult;

/**
 * Normalized consecutive-failure signature for Content Project batch circuit breaker.
 * Excludes article/task ids, titles, UUIDs, timestamps.
 *
 * Hierarchy:
 * - Systemic/routing (pre-provider or route exhaustion) → node-agnostic family.
 * - Content/validation → keep node in signature.
 */
final class ContentProjectBatchFailureSignature
{
    public const THRESHOLD = 3;

    public const SYSTEMIC_ROUTING = 'ai_routing|routes_exhausted';

    public static function fromResult(ArticleExecutionResult $result): string
    {
        if (self::isSystemicRoutingFailure($result)) {
            return self::SYSTEMIC_ROUTING;
        }

        $payload = $result->payload;
        $node = self::normalizeToken(
            (string) ($payload['failed_node'] ?? $payload['hook_key'] ?? $payload['workflow_node'] ?? ''),
        );
        if ($node === '') {
            $node = self::nodeFromMessage($result->message);
        }
        if ($node === '') {
            $node = 'article';
        }

        $classification = self::normalizeToken(
            (string) ($result->errorCode ?? $payload['failure_class'] ?? $payload['classification'] ?? ''),
        );
        if ($classification === '' || self::isExternalWorkflowWrapperCode($classification)) {
            $fromMessage = self::classifyFromMessage($result->message);
            $classification = $fromMessage !== 'error' ? $fromMessage : 'error';
        }

        // Content failures keep node; provider only when a real attempt/provider is known.
        $provider = self::normalizeToken(
            (string) ($payload['ai_provider'] ?? $payload['provider'] ?? $payload['ai_model'] ?? ''),
        );
        if ($provider === '') {
            $provider = self::providerFromMessage($result->message);
        }

        $parts = [$node, $classification];
        if ($provider !== '') {
            $parts[] = $provider;
        }

        return implode('|', $parts);
    }

    public static function isSystemicRoutingFailure(ArticleExecutionResult $result): bool
    {
        $haystack = strtolower(trim(implode(' ', [
            $result->message,
            (string) ($result->errorCode ?? ''),
            (string) ($result->payload['failure_class'] ?? ''),
            (string) ($result->payload['classification'] ?? ''),
            (string) ($result->payload['error_detail'] ?? ''),
        ])));

        return self::messageLooksLikeRouteExhaustion($haystack);
    }

    public static function messageLooksLikeRouteExhaustion(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'ai_routes_exhausted')
            || str_contains($lower, 'routes exhausted')
            || str_contains($lower, 'no eligible ai route')
            || str_contains($lower, 'no eligible route')
            || str_contains($lower, 'all candidates unavailable')
            || str_contains($lower, 'connection unavailable')
            || str_contains($lower, 'no ai route was attempted');
    }

    /**
     * Wrapper codes from ContentProjectErrorCode / legacy aliases — not a real failure class.
     */
    public static function isExternalWorkflowWrapperCode(string $code): bool
    {
        $normalized = strtolower(trim($code));
        $normalized = str_replace(['-', ' '], '_', $normalized);

        return $normalized === 'external_workflow_failed'
            || $normalized === 'content_project_external_workflow_failed'
            || str_ends_with($normalized, '_external_workflow_failed');
    }

    private static function nodeFromMessage(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'outline')) {
            return 'outline';
        }
        if (str_contains($lower, 'vocabulary') || str_contains($lower, 'từ vựng')) {
            return 'vocabulary';
        }
        if (str_contains($lower, 'article') || str_contains($lower, 'writer') || str_contains($lower, 'content.generate')) {
            return 'article';
        }

        return '';
    }

    public static function normalizeMessage(string $message): string
    {
        $message = strtolower(trim($message));
        $message = preg_replace(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '{uuid}',
            $message,
        ) ?? $message;
        $message = preg_replace('/\b\d{6,}\b/', '{n}', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return $message;
    }

    private static function classifyFromMessage(string $message): string
    {
        $lower = self::normalizeMessage($message);

        if (self::messageLooksLikeRouteExhaustion($lower)) {
            return 'routes_exhausted';
        }
        if (str_contains($lower, 'min_length') || str_contains($lower, 'shorter than minimum')) {
            return 'min_length';
        }
        if (str_contains($lower, 'empty') || str_contains($lower, 'không trả về') || str_contains($lower, 'empty output')) {
            return 'empty_response';
        }
        if (str_contains($lower, 'marker') || str_contains($lower, 'output_contract') || str_contains($lower, 'mismatched')) {
            return 'output_contract';
        }
        if (str_contains($lower, 'validation') || str_contains($lower, 'malformed')) {
            return 'validation';
        }
        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
            return 'timeout';
        }
        if (str_contains($lower, '429') || str_contains($lower, 'rate limit')) {
            return 'rate_limit';
        }
        if (str_contains($lower, 'đồng bộ từ khóa') || str_contains($lower, 'canonicalize')) {
            return 'keyword_sync';
        }

        $code = preg_replace('/[^a-z0-9_|.-]+/', '_', $lower) ?? 'error';

        return substr($code, 0, 64) ?: 'error';
    }

    private static function providerFromMessage(string $message): string
    {
        $lower = strtolower($message);
        foreach (['deepseek', 'claude', 'gemini', 'openrouter', 'openai'] as $name) {
            if (str_contains($lower, $name)) {
                return $name;
            }
        }

        return '';
    }

    private static function normalizeToken(string $value): string
    {
        $value = strtolower(trim($value));
        $value = self::normalizeMessage($value);
        $value = preg_replace('/[^a-z0-9_.|-]+/', '_', $value) ?? '';

        return trim($value, '_');
    }
}
