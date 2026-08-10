<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support;

/**
 * Classify Google Imagen / Generative Language image provider errors.
 * Does not invent Vertex internal IDs — those appear only in provider messages.
 */
final class ImagenProviderErrorClassifier
{
    public const PROVIDER_TRANSIENT = 'provider_transient';

    public const PROVIDER_RATE_LIMIT = 'provider_rate_limit';

    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const INVALID_MODEL = 'invalid_model';

    public const INVALID_ENDPOINT = 'invalid_endpoint';

    public const AUTHENTICATION_ERROR = 'authentication_error';

    public const CONFIGURATION_ERROR = 'configuration_error';

    public const INVALID_REQUEST = 'invalid_request';

    public const UNKNOWN_PROVIDER_ERROR = 'unknown_provider_error';

    /**
     * @return array{
     *     classification: string,
     *     retryable: bool,
     *     user_message: string,
     *     technical_details: string,
     * }
     */
    public static function present(string $rawMessage, ?int $httpStatus = null): array
    {
        $technical = trim($rawMessage);
        $classification = self::classify($technical, $httpStatus);
        $retryable = self::isRetryableClassification($classification);

        return [
            'classification' => $classification,
            'retryable' => $retryable,
            'user_message' => self::userMessageFor($classification, $technical),
            'technical_details' => $technical !== '' ? $technical : 'No provider message.',
        ];
    }

    public static function classify(string $rawMessage, ?int $httpStatus = null): string
    {
        $lower = mb_strtolower(trim($rawMessage));

        if ($httpStatus === 401 || $httpStatus === 403
            || str_contains($lower, 'api key not valid')
            || str_contains($lower, 'permission denied')
            || str_contains($lower, 'unauthenticated')
            || str_contains($lower, 'invalid api key')
            || str_contains($lower, 'api_key_invalid')
        ) {
            return self::AUTHENTICATION_ERROR;
        }

        if ($httpStatus === 429
            || str_contains($lower, 'resource exhausted')
            || str_contains($lower, 'rate limit')
            || str_contains($lower, 'quota')
            || str_contains($lower, 'high demand')
        ) {
            return self::PROVIDER_RATE_LIMIT;
        }

        if (
            str_contains($lower, 'fail to execute model')
            || str_contains($lower, 'target=anonymous')
            || str_contains($lower, 'internal endpoint')
            || str_contains($lower, '503')
            || str_contains($lower, 'unavailable')
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'timeout')
            || str_contains($lower, 'curl error 28')
            || str_contains($lower, 'connection reset')
            || str_contains($lower, 'temporarily')
        ) {
            return self::PROVIDER_TRANSIENT;
        }

        if (
            (str_contains($lower, 'not found') && str_contains($lower, 'model'))
            || str_contains($lower, 'is not found')
            || str_contains($lower, 'not supported for')
            || str_contains($lower, 'unknown model')
        ) {
            return self::INVALID_MODEL;
        }

        if (
            str_contains($lower, 'invalid argument')
            || str_contains($lower, 'invalid request')
            || ($httpStatus === 400 && ! str_contains($lower, 'fail to execute'))
        ) {
            return self::INVALID_REQUEST;
        }

        if (str_contains($lower, '404') && str_contains($lower, 'endpoint')) {
            return self::INVALID_ENDPOINT;
        }

        if (
            str_contains($lower, 'chưa có api key')
            || str_contains($lower, 'chưa được gắn kết nối')
            || str_contains($lower, 'kết nối ai đang tắt')
        ) {
            return self::CONFIGURATION_ERROR;
        }

        if (
            str_contains($lower, 'no longer available')
            || str_contains($lower, 'has been shutdown')
            || str_contains($lower, 'deprecated')
        ) {
            return self::PROVIDER_UNAVAILABLE;
        }

        return self::UNKNOWN_PROVIDER_ERROR;
    }

    public static function isRetryableClassification(string $classification): bool
    {
        return in_array($classification, [
            self::PROVIDER_TRANSIENT,
            self::PROVIDER_RATE_LIMIT,
            self::PROVIDER_UNAVAILABLE,
            self::UNKNOWN_PROVIDER_ERROR,
        ], true);
    }

    private static function userMessageFor(string $classification, string $technical): string
    {
        $viTransient = 'Imagen không thể thực thi model ở lần gọi này. Đây có thể là lỗi tạm thời của nhà cung cấp. Bạn có thể thử chạy lại.';

        try {
            return match ($classification) {
                self::PROVIDER_TRANSIENT,
                self::PROVIDER_RATE_LIMIT => (string) __('seo-content-ai::filament.imagen.user_transient'),
                self::AUTHENTICATION_ERROR => (string) __('seo-content-ai::filament.imagen.user_auth'),
                self::INVALID_MODEL => (string) __('seo-content-ai::filament.imagen.user_invalid_model'),
                self::INVALID_REQUEST => (string) __('seo-content-ai::filament.imagen.user_invalid_request'),
                self::CONFIGURATION_ERROR => (string) __('seo-content-ai::filament.imagen.user_config'),
                self::PROVIDER_UNAVAILABLE => (string) __('seo-content-ai::filament.imagen.user_unavailable'),
                default => (string) __('seo-content-ai::filament.imagen.user_unknown'),
            };
        } catch (\Throwable) {
            return match ($classification) {
                self::PROVIDER_TRANSIENT,
                self::PROVIDER_RATE_LIMIT => $viTransient,
                self::AUTHENTICATION_ERROR => 'Image generation failed due to an authentication problem.',
                self::INVALID_MODEL => 'The selected Imagen model is invalid or unavailable.',
                self::INVALID_REQUEST => 'The image request was rejected as invalid.',
                self::CONFIGURATION_ERROR => 'Image generation is not configured correctly.',
                self::PROVIDER_UNAVAILABLE => 'The Imagen model is currently unavailable.',
                default => $technical !== '' ? $technical : 'Image generation failed.',
            };
        }
    }

    /**
     * Strip secrets from URLs / bodies before logging or UI technical panel.
     */
    public static function redactSecrets(string $text): string
    {
        $text = preg_replace('/([?&]key=)[^&\s]+/i', '$1[REDACTED]', $text) ?? $text;
        $text = preg_replace('/(Bearer\s+)[^\s]+/i', '$1[REDACTED]', $text) ?? $text;
        $text = preg_replace('/("?(?:api_key|access_token|private_key)"?\s*[:=]\s*")[^"]*(")/i', '$1[REDACTED]$2', $text) ?? $text;

        return $text;
    }
}
