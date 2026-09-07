<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Connection credential usability for routing eligibility.
 *
 * Distinguishes "ciphertext present in DB" from a decryptable key that can
 * actually authenticate a provider call. Placeholder / truncated keys must not
 * enter the eligible route pool (they 401 → connection_locked → attempts=0).
 */
final class AiConnectionCredential
{
    /** OpenRouter / Gemini / Claude / DeepSeek production keys are far longer. */
    public const MIN_USABLE_LENGTH = 8;

    public static function isUsable(mixed $apiKey): bool
    {
        if (! is_string($apiKey) && ! is_numeric($apiKey)) {
            return false;
        }
        $key = trim((string) $apiKey);

        return $key !== '' && mb_strlen($key) >= self::MIN_USABLE_LENGTH;
    }
}
