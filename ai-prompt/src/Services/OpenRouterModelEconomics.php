<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

/**
 * Derive free/paid and chat-text eligibility from OpenRouter GET /models metadata.
 * Does not hard-code a free-model snapshot.
 */
final class OpenRouterModelEconomics
{
    public const FREE_ROUTER_ID = 'openrouter/free';

    public const FREE_ROUTER_LABEL = 'OpenRouter Free Router';

    /**
     * @param  array<string, mixed>  $capabilities
     */
    public static function isFree(array $capabilities, string $rawModelName): bool
    {
        $raw = strtolower(trim($rawModelName));
        if ($raw === self::FREE_ROUTER_ID || str_ends_with($raw, ':free')) {
            return true;
        }

        $pricing = self::pricingBag($capabilities);
        if ($pricing === []) {
            return false;
        }

        return self::priceUnits($pricing['prompt'] ?? $pricing['input'] ?? null) === 0.0
            && self::priceUnits($pricing['completion'] ?? $pricing['output'] ?? null) === 0.0;
    }

    public static function modelIsFree(SeoAiModel $model): bool
    {
        $caps = is_array($model->capabilities) ? $model->capabilities : [];

        return self::isFree($caps, (string) $model->raw_model_name);
    }

    /**
     * Chat/text generation only — embeddings, rerankers, image/video/audio stay out of text tabs.
     *
     * @param  array<string, mixed>  $capabilities
     */
    public static function isChatTextModel(array $capabilities, string $rawModelName): bool
    {
        $raw = strtolower(trim($rawModelName));
        if ($raw === self::FREE_ROUTER_ID) {
            return true;
        }
        if (self::looksLikeNonChatId($raw)) {
            return false;
        }

        $modality = self::architectureModality($capabilities);
        if ($modality !== '') {
            if (str_contains($modality, 'embedding')
                || str_contains($modality, 'rerank')
                || str_contains($modality, '->image')
                || str_contains($modality, '->video')
                || str_contains($modality, '->audio')
                || str_contains($modality, 'image->')
                || str_contains($modality, 'audio->')) {
                return false;
            }
            if (str_contains($modality, 'text')) {
                return true;
            }
        }

        $resolved = $capabilities['resolved'] ?? [];
        if (is_array($resolved) && $resolved !== []) {
            $joined = implode(' ', array_map(static fn (mixed $v): string => (string) $v, $resolved));
            if (str_contains($joined, 'image.generate') || str_contains($joined, 'video.generate')) {
                return false;
            }
            if (str_contains($joined, 'text.generate') || str_contains($joined, 'text.reasoning')) {
                return true;
            }
        }

        return ! self::looksLikeNonChatId($raw);
    }

    public static function looksLikeNonChatId(string $raw): bool
    {
        $raw = strtolower($raw);

        return str_contains($raw, 'embed')
            || str_contains($raw, 'rerank')
            || str_contains($raw, 'whisper')
            || str_contains($raw, 'tts')
            || str_contains($raw, 'moderation')
            || str_contains($raw, 'codec')
            || str_contains($raw, 'transcri')
            || (str_contains($raw, 'image') && ! str_contains($raw, 'vl'))
            || str_contains($raw, 'dall-e')
            || str_contains($raw, 'flux')
            || str_contains($raw, 'imagen')
            || str_contains($raw, 'veo')
            || str_contains($raw, 'kling')
            || str_contains($raw, 'runway')
            || str_contains($raw, 'stable-diffusion');
    }

    public static function isOpenRouterFreeRouter(string $rawModelName): bool
    {
        return strtolower(trim($rawModelName)) === self::FREE_ROUTER_ID;
    }

    public static function isOpenRouterProvider(string $provider): bool
    {
        return strtolower(trim($provider)) === ApiConnectionProviders::OPENROUTER;
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @return array<string, mixed>
     */
    public static function pricingBag(array $capabilities): array
    {
        $meta = is_array($capabilities['provider_metadata'] ?? null) ? $capabilities['provider_metadata'] : [];
        $pricing = $meta['pricing'] ?? [];

        return is_array($pricing) ? $pricing : [];
    }

    /**
     * @param  array<string, mixed>  $capabilities
     */
    public static function architectureModality(array $capabilities): string
    {
        $meta = is_array($capabilities['provider_metadata'] ?? null) ? $capabilities['provider_metadata'] : [];
        $architecture = is_array($meta['architecture'] ?? null) ? $meta['architecture'] : [];

        return strtolower(trim((string) ($architecture['modality'] ?? $architecture['output_modalities'][0] ?? '')));
    }

    public static function priceUnits(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if (! is_numeric($trimmed)) {
            return null;
        }

        return (float) $trimmed;
    }
}
