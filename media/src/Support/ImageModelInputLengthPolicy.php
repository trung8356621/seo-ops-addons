<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support;

use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;

/**
 * Chọn tier model sinh ảnh theo độ dài prompt đã render (sau replace biến, UTF-8).
 */
final class ImageModelInputLengthPolicy
{
    public const FLASH_MAX_CHARS = 1000;

    public const LONG_INPUT_CHARS = 5000;

    public const TIER_FLASH = 'flash';

    public const TIER_PRO = 'pro';

    public const TIER_IMAGEN = 'imagen';

    public const TIER_OTHER = 'other';

    public static function preferredTier(int $inputLength): string
    {
        return $inputLength <= self::FLASH_MAX_CHARS ? self::TIER_FLASH : self::TIER_PRO;
    }

    public static function tierForModel(string $modelSlug): string
    {
        $slug = GoogleAiModelRegistry::normalizeSlug($modelSlug);

        if ($slug === '') {
            return self::TIER_OTHER;
        }

        if (GoogleAiModelRegistry::isImagenModel($slug)) {
            return self::TIER_IMAGEN;
        }

        if (! GoogleAiModelRegistry::isGeminiNativeImageModel($slug)) {
            return self::TIER_OTHER;
        }

        if (preg_match('/(?:^|-)pro(?:-|$)/', $slug) === 1 || str_contains($slug, '-pro-image')) {
            return self::TIER_PRO;
        }

        if (preg_match('/(?:^|-)flash(?:-|$)/', $slug) === 1 || str_contains($slug, '-flash-image')) {
            return self::TIER_FLASH;
        }

        return str_contains($slug, 'pro') ? self::TIER_PRO : self::TIER_FLASH;
    }

    /**
     * @param  list<string>  $models
     * @return list<string>
     */
    public static function reorderModels(array $models, int $inputLength): array
    {
        if ($models === []) {
            return [];
        }

        $buckets = [
            self::TIER_FLASH => [],
            self::TIER_PRO => [],
            self::TIER_IMAGEN => [],
            self::TIER_OTHER => [],
        ];

        foreach ($models as $model) {
            $slug = GoogleAiModelRegistry::normalizeSlug((string) $model);
            if ($slug === '') {
                continue;
            }

            $buckets[self::tierForModel($slug)][] = $slug;
        }

        $preferredTier = self::preferredTier(max(0, $inputLength));

        $ordered = $preferredTier === self::TIER_FLASH
            ? array_merge($buckets[self::TIER_FLASH], $buckets[self::TIER_PRO], $buckets[self::TIER_IMAGEN], $buckets[self::TIER_OTHER])
            : array_merge($buckets[self::TIER_PRO], $buckets[self::TIER_FLASH], $buckets[self::TIER_IMAGEN], $buckets[self::TIER_OTHER]);

        return array_values(array_unique($ordered));
    }

    public static function tierHint(string $tier): string
    {
        return match ($tier) {
            self::TIER_FLASH => __('seo-content-ai::filament.settings_workflows.image_model_tier_flash'),
            self::TIER_PRO => __('seo-content-ai::filament.settings_workflows.image_model_tier_pro'),
            self::TIER_IMAGEN => __('seo-content-ai::filament.settings_workflows.image_model_tier_imagen'),
            default => '',
        };
    }

    /**
     * @return list<array{range: string, tier: string, reason: string}>
     */
    public static function routingTableRows(): array
    {
        return [
            [
                'range' => '0 – 300',
                'tier' => self::TIER_FLASH,
                'reason' => __('seo-content-ai::filament.settings_workflows.image_model_rule_short'),
            ],
            [
                'range' => '301 – 1.000',
                'tier' => self::TIER_FLASH,
                'reason' => __('seo-content-ai::filament.settings_workflows.image_model_rule_medium_flash'),
            ],
            [
                'range' => '1.001 – 2.500',
                'tier' => self::TIER_PRO,
                'reason' => __('seo-content-ai::filament.settings_workflows.image_model_rule_complex'),
            ],
            [
                'range' => '2.501 – 5.000',
                'tier' => self::TIER_PRO,
                'reason' => __('seo-content-ai::filament.settings_workflows.image_model_rule_infographic'),
            ],
            [
                'range' => '> 5.000',
                'tier' => self::TIER_PRO,
                'reason' => __('seo-content-ai::filament.settings_workflows.image_model_rule_very_long'),
            ],
        ];
    }

    public static function measureCompiledPromptLength(?string $compiledPrompt): int
    {
        return mb_strlen(trim((string) $compiledPrompt));
    }

    public static function shouldTruncateCompiledPrompt(int $compiledPromptLength): bool
    {
        return $compiledPromptLength > self::LONG_INPUT_CHARS;
    }
}
