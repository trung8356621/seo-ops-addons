<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Temporary gated latency spans for diagnostic runs.
 * Enable: putenv('AI_LATENCY_DIAG=1') or env AI_LATENCY_DIAG=1.
 * Zero cost when disabled (early return).
 */
final class AiLatencyDiag
{
    private static bool $forcedOn = false;

    /** @var array<string, float> */
    private static array $spansMs = [];

    /** @var array<string, mixed> */
    private static array $meta = [];

    /** @var list<array<string, mixed>> */
    private static array $providerAttempts = [];

    public static function enable(): void
    {
        self::$forcedOn = true;
        self::reset();
    }

    public static function disable(): void
    {
        self::$forcedOn = false;
        self::reset();
    }

    public static function isEnabled(): bool
    {
        if (self::$forcedOn) {
            return true;
        }

        $raw = getenv('AI_LATENCY_DIAG');
        if ($raw === false || $raw === '') {
            $raw = $_ENV['AI_LATENCY_DIAG'] ?? $_SERVER['AI_LATENCY_DIAG'] ?? null;
        }

        return $raw === '1' || $raw === 1 || $raw === true || $raw === 'true';
    }

    public static function reset(): void
    {
        self::$spansMs = [];
        self::$meta = [];
        self::$providerAttempts = [];
    }

    /**
     * @template T
     * @param  callable(): T  $fn
     * @return T
     */
    public static function time(string $name, callable $fn): mixed
    {
        if (! self::isEnabled()) {
            return $fn();
        }

        $started = hrtime(true);
        try {
            return $fn();
        } finally {
            self::$spansMs[$name] = round((hrtime(true) - $started) / 1_000_000, 3);
        }
    }

    public static function addMs(string $name, float $ms): void
    {
        if (! self::isEnabled()) {
            return;
        }

        self::$spansMs[$name] = round((self::$spansMs[$name] ?? 0.0) + $ms, 3);
    }

    public static function setMs(string $name, float $ms): void
    {
        if (! self::isEnabled()) {
            return;
        }

        self::$spansMs[$name] = round($ms, 3);
    }

    public static function setMeta(string $key, mixed $value): void
    {
        if (! self::isEnabled()) {
            return;
        }

        self::$meta[$key] = $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function addProviderAttempt(array $row): void
    {
        if (! self::isEnabled()) {
            return;
        }

        self::$providerAttempts[] = $row;
    }

    /**
     * @return array{
     *   spans_ms: array<string, float>,
     *   meta: array<string, mixed>,
     *   provider_attempts: list<array<string, mixed>>
     * }
     */
    public static function report(): array
    {
        return [
            'spans_ms' => self::$spansMs,
            'meta' => self::$meta,
            'provider_attempts' => self::$providerAttempts,
        ];
    }
}
