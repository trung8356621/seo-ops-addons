<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Normalize PromptResult / runtime error values for human display.
 * Never surface raw booleans like `false`.
 */
final class PromptAiCallErrorNormalizer
{
    public static function display(mixed $error, string $fallback = 'AI call failed.'): ?string
    {
        if ($error === null) {
            return null;
        }

        if (is_bool($error)) {
            return $error ? $fallback : null;
        }

        if (is_array($error)) {
            $message = $error['message'] ?? $error['error'] ?? $error['detail'] ?? null;
            if (is_string($message) || is_numeric($message) || is_bool($message)) {
                return self::display($message, $fallback);
            }

            return $fallback;
        }

        $text = trim((string) $error);
        if ($text === '') {
            return null;
        }

        if (strtolower($text) === 'false' || strtolower($text) === 'null') {
            return $fallback;
        }

        if (strtolower($text) === 'true') {
            return $fallback;
        }

        return $text;
    }
}
