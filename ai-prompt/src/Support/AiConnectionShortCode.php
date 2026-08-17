<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Display-only short codes for AI connections. Never used as execution identity.
 */
final class AiConnectionShortCode
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 8;

    public static function builtin(string $providerKey): ?string
    {
        return match (strtolower(trim($providerKey))) {
            ApiConnectionProviders::GEMINI => 'GG',
            ApiConnectionProviders::DEEPSEEK => 'DS',
            ApiConnectionProviders::OPENROUTER => 'OR',
            ApiConnectionProviders::CLAUDE, 'anthropic' => 'AN',
            'openai' => 'OA',
            default => null,
        };
    }

    /**
     * Normalize explicit short codes. Returns null when empty/invalid.
     */
    public static function normalize(?string $raw): ?string
    {
        $value = strtoupper(trim((string) $raw));
        if ($value === '') {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }
        $value = preg_replace('/[^A-Z0-9-]/', '', $value) ?? '';
        $length = strlen($value);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return null;
        }

        return $value;
    }

    /**
     * Deterministic abbreviation from provider name/key when short_code is absent.
     */
    public static function generate(string $nameOrKey): string
    {
        $source = trim($nameOrKey);
        $words = preg_split('/[\s_\-]+/u', $source) ?: [];
        $words = array_values(array_filter(array_map(static fn (string $w): string => trim($w), $words)));
        if (count($words) >= 2) {
            $code = strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        } else {
            $alnum = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $source) ?? '');
            $code = substr($alnum, 0, 2);
        }
        $normalized = self::normalize($code);
        if ($normalized !== null) {
            return $normalized;
        }

        return 'AI';
    }

    public static function assertValidExplicit(string $raw): string
    {
        $normalized = self::normalize($raw);
        if ($normalized === null) {
            throw new \InvalidArgumentException(
                'short_code must be 2–8 characters: A-Z, 0-9, hyphen only.',
            );
        }

        return $normalized;
    }
}
