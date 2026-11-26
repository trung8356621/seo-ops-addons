<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Bounded wall-clock for short interactive Prompt Hooks (Meta Description, Title, …).
 *
 * Does NOT apply to long-form article.content.generate — that keeps the 180s
 * OpenAI-compatible adapter timeout and resilience attempt settings.
 */
final class ShortPromptHookExecutionBounds
{
    /** Per-attempt HTTP timeout — must cover reasoning-then-answer NIM models that buffer until complete (MiniMax ≈40–65s). */
    public const HTTP_TIMEOUT_SECONDS = 70;

    /**
     * Total wall-clock for the whole short-hook routing loop (all attempts).
     * Prevents 3×70s sequential hangs in the browser. Long-form article generation is excluded.
     */
    public const TOTAL_WALL_CLOCK_SECONDS = 100;

    /** Enough room to skip one empty/instruction-echo route and still succeed. */
    public const MAX_AI_ATTEMPTS = 3;

    public const MAX_FREE_ATTEMPTS = 1;

    /**
     * Absolute microtime deadline bound for the active short-hook routing loop.
     * Set by AiModelRouterService so providerOptions() can cap in-flight HTTP.
     */
    private static ?float $activeDeadlineUnix = null;

    /**
     * Short DirectFit editor hooks that must fail fast under broken provisional routes.
     *
     * @var list<string>
     */
    private const HOOKS = [
        'article.title_suggestion',
        'article.meta_description_suggestion',
        'article.faq.generate',
        'article.featured_snippet.generate',
        'article.comment.generate',
    ];

    public static function matches(?string $hookKey): bool
    {
        $hook = strtolower(trim((string) $hookKey));
        if ($hook === '' || ArticleContentGenerationHooks::matches($hook)) {
            return false;
        }

        return in_array($hook, self::HOOKS, true);
    }

    /**
     * Bind the routing-loop wall deadline so each provider HTTP call inherits remaining budget.
     */
    public static function bindDeadline(?float $deadlineUnix): void
    {
        self::$activeDeadlineUnix = $deadlineUnix;
    }

    public static function clearDeadline(): void
    {
        self::$activeDeadlineUnix = null;
    }

    public static function activeDeadline(): ?float
    {
        return self::$activeDeadlineUnix;
    }

    /**
     * Seconds left on the active short-hook wall clock (null when unbound).
     */
    public static function remainingSeconds(?float $now = null): ?float
    {
        if (self::$activeDeadlineUnix === null) {
            return null;
        }

        return self::$activeDeadlineUnix - ($now ?? microtime(true));
    }

    /**
     * @return array{http_timeout_seconds: int}
     */
    public static function providerOptions(?string $hookKey): array
    {
        if (! self::matches($hookKey)) {
            return [];
        }

        $timeout = self::HTTP_TIMEOUT_SECONDS;
        $remaining = self::remainingSeconds();
        if ($remaining !== null) {
            // Never let a single HTTP attempt exceed the remaining wall budget.
            $timeout = max(1, min($timeout, (int) floor($remaining)));
        }

        return ['http_timeout_seconds' => $timeout];
    }

    public static function capMaxAiAttempts(?int $current, ?string $hookKey): ?int
    {
        if (! self::matches($hookKey)) {
            return $current;
        }

        $cap = self::MAX_AI_ATTEMPTS;

        return $current === null ? $cap : min($current, $cap);
    }

    public static function capMaxFreeAttempts(?int $current, ?string $hookKey): ?int
    {
        if (! self::matches($hookKey)) {
            return $current;
        }

        $cap = self::MAX_FREE_ATTEMPTS;

        return $current === null ? $cap : min($current, $cap);
    }

    /**
     * Absolute microtime deadline for short-hook routing, or null when unbounded.
     */
    public static function wallClockDeadline(?string $hookKey, ?float $startedAt = null): ?float
    {
        if (! self::matches($hookKey)) {
            return null;
        }

        return ($startedAt ?? microtime(true)) + self::TOTAL_WALL_CLOCK_SECONDS;
    }
}
