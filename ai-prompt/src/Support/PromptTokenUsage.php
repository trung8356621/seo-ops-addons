<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

final class PromptTokenUsage
{
    /**
     * @param  array<string, mixed>|null  $usage
     */
    public static function total(?array $usage): ?int
    {
        if ($usage === null || $usage === []) {
            return null;
        }

        if (isset($usage['totalTokenCount'])) {
            return (int) $usage['totalTokenCount'];
        }

        if (isset($usage['input_tokens']) || isset($usage['output_tokens'])) {
            return (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0);
        }

        if (isset($usage['promptTokenCount']) || isset($usage['candidatesTokenCount'])) {
            return (int) ($usage['promptTokenCount'] ?? 0) + (int) ($usage['candidatesTokenCount'] ?? 0);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $usage
     */
    public static function formatLabel(?array $usage): ?string
    {
        $total = self::total($usage);

        if ($total === null || $total <= 0) {
            return null;
        }

        return number_format($total, 0, ',', '.') . ' token';
    }
}
