<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * History display helpers for routing_attempts (no UI framework coupling).
 */
final class AiHistoryRouteDisplay
{
    public const MODEL_NO_ATTEMPT = 'No model attempted';

    public const MODEL_LEGACY_UNAVAILABLE = 'Legacy execution — routing metadata unavailable';

    /**
     * @param  array<string, mixed>  $prompt
     * @param  list<array<string, mixed>>  $routingAttempts
     */
    public static function resolveModelDisplay(array $prompt, array $routingAttempts): string
    {
        $actualAttempts = 0;
        $lastAttemptModel = null;
        $successModel = null;
        foreach ($routingAttempts as $row) {
            if (! is_array($row)) {
                continue;
            }
            $result = (string) ($row['result'] ?? '');
            if ($result === 'success' || $result === 'failed') {
                $actualAttempts++;
                $label = self::formatAttemptRoute($row);
                $lastAttemptModel = $label;
                if ($result === 'success') {
                    $successModel = $label;
                }
            }
        }

        if ($successModel !== null) {
            return $successModel;
        }
        if ($actualAttempts > 0 && $lastAttemptModel !== null) {
            return $lastAttemptModel;
        }
        if ($routingAttempts !== [] && $actualAttempts === 0) {
            $reason = '';
            foreach ($routingAttempts as $row) {
                if (is_array($row) && (string) ($row['result'] ?? '') === 'skipped') {
                    $reason = trim((string) ($row['skip_reason'] ?? ''));
                    if ($reason !== '') {
                        break;
                    }
                }
            }

            return self::MODEL_NO_ATTEMPT.($reason !== '' ? ' ('.$reason.')' : '');
        }

        $existing = trim((string) ($prompt['model'] ?? $prompt['render_model'] ?? ''));
        if ($existing !== '' && strcasecmp($existing, 'Unknown model') !== 0) {
            return $existing;
        }

        if ($routingAttempts === [] && ($existing === '' || strcasecmp($existing, 'Unknown model') === 0)) {
            return self::MODEL_LEGACY_UNAVAILABLE;
        }

        return $existing !== '' ? $existing : self::MODEL_NO_ATTEMPT;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function formatAttemptRoute(array $row): string
    {
        $provider = trim((string) ($row['provider'] ?? ''));
        $model = trim((string) ($row['actual_provider_model'] ?? $row['model'] ?? $row['candidate_model'] ?? ''));
        $shortProvider = $provider;
        if (str_contains(strtolower($provider), 'openrouter')) {
            $shortProvider = 'OR';
        } elseif (str_contains(strtolower($provider), 'deepseek')) {
            $shortProvider = 'DS';
        } elseif (str_contains(strtolower($provider), 'gemini') || str_contains(strtolower($provider), 'google')) {
            $shortProvider = 'GG';
        }

        if ($shortProvider !== '' && $model !== '') {
            return $model.' / '.$shortProvider;
        }

        return $model !== '' ? $model : ($shortProvider !== '' ? $shortProvider : 'route');
    }
}
