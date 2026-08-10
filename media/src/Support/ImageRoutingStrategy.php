<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support;

use Omnichannel\Addons\Content\Support\TypographyComplexity;
use Omnichannel\Addons\Seo\Support\GeminiModelVersionPolicy;
use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;
use Omnichannel\Addons\Seo\Support\RenderingPreference;

/**
 * Entry duy nhất chọn danh sách model thử khi sinh ảnh.
 */
final class ImageRoutingStrategy
{
    public function __construct(
        private readonly ImageCapabilityResolver $capabilityResolver = new ImageCapabilityResolver(),
    ) {}

    /**
     * @param  list<string>|null  $configuredPriorityList
     * @param  list<string>  $adminEnabledUnknownSlugs  Unknown model admin bật thủ công
     * @param  array<string, array<string, mixed>|null>  $capabilitiesBySlug  slug => capabilities JSON
     * @return list<string>
     */
    public function modelsToTry(
        ImageToolType $toolType,
        RenderingPreference $preference,
        ?int $compiledPromptLength = null,
        bool $productContext = false,
        ?TypographyComplexity $typographyComplexity = null,
        ?array $configuredPriorityList = null,
        array $adminEnabledUnknownSlugs = [],
        array $capabilitiesBySlug = [],
    ): array {
        if (! $toolType->isImagePipeline()) {
            return [];
        }

        $configuredResolved = GoogleAiModelRegistry::resolveImageModelPriorityList($configuredPriorityList);
        $eligible = $this->eligibleModelsFromPriority(
            priorityList: $configuredResolved,
            toolType: $toolType,
            preference: $preference,
            compiledPromptLength: $compiledPromptLength,
            productContext: $productContext,
            typographyComplexity: $typographyComplexity,
            adminEnabledUnknownSlugs: $adminEnabledUnknownSlugs,
            capabilitiesBySlug: $capabilitiesBySlug,
        );
        if ($eligible !== []) {
            return $eligible;
        }

        // Typography keeps empty here — general-image fallback is gated by executionPolicy.
        if ($toolType->isTypography()) {
            return [];
        }

        // Contextual fallback: stored priority may be globally non-empty yet unusable under
        // current filters (e.g. Imagen-only + productContext). Do NOT mutate user settings.
        $canonical = GoogleAiModelRegistry::defaultImageModelPriority();
        if ($configuredResolved === $canonical) {
            return [];
        }

        return $this->eligibleModelsFromPriority(
            priorityList: $canonical,
            toolType: $toolType,
            preference: $preference,
            compiledPromptLength: $compiledPromptLength,
            productContext: $productContext,
            typographyComplexity: $typographyComplexity,
            adminEnabledUnknownSlugs: $adminEnabledUnknownSlugs,
            capabilitiesBySlug: $capabilitiesBySlug,
        );
    }

    /**
     * @param  list<string>  $priorityList
     * @param  list<string>  $adminEnabledUnknownSlugs
     * @param  array<string, array<string, mixed>|null>  $capabilitiesBySlug
     * @return list<string>
     */
    private function eligibleModelsFromPriority(
        array $priorityList,
        ImageToolType $toolType,
        RenderingPreference $preference,
        ?int $compiledPromptLength,
        bool $productContext,
        ?TypographyComplexity $typographyComplexity,
        array $adminEnabledUnknownSlugs,
        array $capabilitiesBySlug,
    ): array {
        $candidates = GeminiModelVersionPolicy::filterEligibleForAutoRouting($priorityList, $capabilitiesBySlug);
        $enabledUnknown = array_fill_keys(
            array_map(
                static fn (string $slug): string => GoogleAiModelRegistry::normalizeSlug($slug),
                $adminEnabledUnknownSlugs,
            ),
            true,
        );

        $filtered = [];
        foreach ($candidates as $slug) {
            $normalized = GoogleAiModelRegistry::normalizeSlug($slug);
            if ($normalized === '') {
                continue;
            }

            if (! GeminiModelVersionPolicy::isEligibleForAutoRouting(
                $normalized,
                is_array($capabilitiesBySlug[$normalized] ?? $capabilitiesBySlug[$slug] ?? null)
                    ? ($capabilitiesBySlug[$normalized] ?? $capabilitiesBySlug[$slug] ?? null)
                    : null,
            )) {
                continue;
            }

            $stored = $capabilitiesBySlug[$normalized] ?? $capabilitiesBySlug[$slug] ?? null;
            $capabilities = $this->capabilityResolver->resolve($normalized, is_array($stored) ? $stored : null);
            $hasImageGeneration = in_array(ImageCapability::ImageGeneration->value, $capabilities, true);
            $isUnknown = in_array(ImageCapability::Unknown->value, $capabilities, true) && ! $hasImageGeneration;

            if ($isUnknown && ! isset($enabledUnknown[$normalized])) {
                continue;
            }

            if (! $hasImageGeneration && ! isset($enabledUnknown[$normalized])) {
                continue;
            }

            if ($toolType->isTypography()) {
                if (! in_array(ImageCapability::TypographySupported->value, $capabilities, true)) {
                    continue;
                }

                if (GeminiModelVersionPolicy::isDeprecatedOrShutdown($normalized, is_array($stored) ? $stored : null)) {
                    continue;
                }
            }

            if ($productContext && GoogleAiModelRegistry::isImagenModel($normalized)) {
                continue;
            }

            $filtered[] = $normalized;
        }

        $filtered = array_values(array_unique($filtered));
        if ($filtered === []) {
            return [];
        }

        $reordered = $this->reorder($filtered, $toolType, $preference, $compiledPromptLength, $typographyComplexity);

        return $toolType->isTypography()
            ? GeminiModelVersionPolicy::preferStableFirst($reordered)
            : $reordered;
    }

    /**
     * @param  list<string>|null  $configuredPriorityList
     * @param  list<string>  $adminEnabledUnknownSlugs
     * @param  array<string, array<string, mixed>|null>  $capabilitiesBySlug
     */
    public function executionPolicy(
        ImageToolType $toolType,
        RenderingPreference $preference,
        ?TypographyComplexity $typographyComplexity = null,
        ?int $compiledPromptLength = null,
        bool $productContext = false,
        ?array $configuredPriorityList = null,
        array $adminEnabledUnknownSlugs = [],
        array $capabilitiesBySlug = [],
        bool $validationEnabled = true,
        ?float $passThresholdOverride = null,
        ?int $maxCandidatesOverride = null,
        bool $allowGeneralImageFallback = false,
        ?array $generalImageFallbackPriorityList = null,
    ): ImageRoutingExecutionPolicy {
        $models = $this->modelsToTry(
            toolType: $toolType,
            preference: $preference,
            compiledPromptLength: $compiledPromptLength,
            productContext: $productContext,
            typographyComplexity: $typographyComplexity,
            configuredPriorityList: $configuredPriorityList,
            adminEnabledUnknownSlugs: $adminEnabledUnknownSlugs,
            capabilitiesBySlug: $capabilitiesBySlug,
        );

        $typographyWarning = false;
        if ($toolType->isTypography() && $models === [] && $allowGeneralImageFallback) {
            // Fallback dùng General Image Priority — không throw «No typography model».
            $models = $this->modelsToTry(
                toolType: ImageToolType::Image,
                preference: $preference,
                compiledPromptLength: $compiledPromptLength,
                productContext: $productContext,
                typographyComplexity: $typographyComplexity,
                configuredPriorityList: $generalImageFallbackPriorityList ?? $configuredPriorityList,
                adminEnabledUnknownSlugs: $adminEnabledUnknownSlugs,
                capabilitiesBySlug: $capabilitiesBySlug,
            );
            $typographyWarning = $models !== [];
        }

        if (! $toolType->isTypography()) {
            return new ImageRoutingExecutionPolicy(
                models: $models,
                candidateCount: 1,
                resolution: '2K',
                validationRequired: false,
                minimumScore: 1.0,
                maxRenderAttempts: 1,
                allowGeneralImageFallback: false,
                typographyWarning: false,
            );
        }

        $complexity = $typographyComplexity ?? TypographyComplexity::empty();
        $exactText = $complexity->exactTextRequired || $complexity->visibleTextBlocks !== [];
        $light = $complexity->isLight();
        $heavy = $complexity->isHeavy();

        $candidateCount = match ($preference) {
            RenderingPreference::CostFirst => ($exactText && ! $light) ? 1 : 1,
            RenderingPreference::QualityFirst => $exactText ? 3 : ($light ? 1 : 2),
            RenderingPreference::Balanced => $exactText ? ($light ? 1 : 2) : 1,
        };

        if ($maxCandidatesOverride !== null && $maxCandidatesOverride > 0) {
            $candidateCount = min(3, $maxCandidatesOverride);
        }

        $candidateCount = max(1, min(3, $candidateCount));

        $validationRequired = $validationEnabled
            && ($exactText || $preference !== RenderingPreference::CostFirst || ! $light);

        if ($preference === RenderingPreference::CostFirst) {
            $validationRequired = $validationEnabled && $complexity->exactTextRequired;
        }

        $minimumScore = $passThresholdOverride ?? match ($preference) {
            RenderingPreference::CostFirst => 0.85,
            RenderingPreference::QualityFirst => 0.95,
            RenderingPreference::Balanced => 0.90,
        };

        $resolution = match (true) {
            $preference === RenderingPreference::QualityFirst && $heavy => '4K',
            default => '2K',
        };

        $maxRenderAttempts = match ($preference) {
            RenderingPreference::CostFirst => 1,
            default => 1,
        };

        return new ImageRoutingExecutionPolicy(
            models: $models,
            candidateCount: $candidateCount,
            resolution: $resolution,
            validationRequired: $validationRequired,
            minimumScore: $minimumScore,
            maxRenderAttempts: $maxRenderAttempts,
            allowGeneralImageFallback: $allowGeneralImageFallback,
            typographyWarning: $typographyWarning,
        );
    }

    /**
     * @param  list<string>  $models
     * @return list<string>
     */
    private function reorder(
        array $models,
        ImageToolType $toolType,
        RenderingPreference $preference,
        ?int $compiledPromptLength,
        ?TypographyComplexity $typographyComplexity,
    ): array {
        // Typography: không dùng tổng độ dài prompt làm sole router.
        // Phase 2: đọc $typographyComplexity (visible text / blocks / layout).
        if ($toolType->isTypography()) {
            return $this->reorderTypography($models, $preference, $typographyComplexity);
        }

        return match ($preference) {
            RenderingPreference::CostFirst => $this->preferTier($models, ImageModelInputLengthPolicy::TIER_FLASH),
            RenderingPreference::QualityFirst => $this->preferTier($models, ImageModelInputLengthPolicy::TIER_PRO),
            RenderingPreference::Balanced => ImageModelInputLengthPolicy::reorderModels(
                $models,
                max(0, $compiledPromptLength ?? 0),
            ),
        };
    }

    /**
     * @param  list<string>  $models
     * @return list<string>
     */
    private function reorderTypography(
        array $models,
        RenderingPreference $preference,
        ?TypographyComplexity $typographyComplexity,
    ): array {
        if ($typographyComplexity !== null && ! $typographyComplexity->isEmpty()) {
            if ($typographyComplexity->isHeavy()) {
                return $this->preferTier(
                    $models,
                    ImageModelInputLengthPolicy::TIER_PRO,
                    preferTypographyRecommended: true,
                );
            }

            if ($typographyComplexity->isLight() && $preference === RenderingPreference::CostFirst) {
                return $this->preferTier($models, ImageModelInputLengthPolicy::TIER_FLASH);
            }
        }

        return match ($preference) {
            RenderingPreference::CostFirst => $this->preferTier($models, ImageModelInputLengthPolicy::TIER_FLASH),
            RenderingPreference::QualityFirst,
            RenderingPreference::Balanced => $this->preferTier(
                $models,
                ImageModelInputLengthPolicy::TIER_PRO,
                preferTypographyRecommended: true,
            ),
        };
    }

    /**
     * @param  list<string>  $models
     * @return list<string>
     */
    private function preferTier(
        array $models,
        string $preferredTier,
        bool $preferTypographyRecommended = false,
    ): array {
        $buckets = [
            'recommended' => [],
            ImageModelInputLengthPolicy::TIER_FLASH => [],
            ImageModelInputLengthPolicy::TIER_PRO => [],
            ImageModelInputLengthPolicy::TIER_IMAGEN => [],
            ImageModelInputLengthPolicy::TIER_OTHER => [],
        ];

        foreach ($models as $model) {
            if ($preferTypographyRecommended
                && $this->capabilityResolver->hasCapability($model, ImageCapability::TypographyRecommended->value)
            ) {
                $buckets['recommended'][] = $model;

                continue;
            }

            $buckets[ImageModelInputLengthPolicy::tierForModel($model)][] = $model;
        }

        $ordered = $preferredTier === ImageModelInputLengthPolicy::TIER_FLASH
            ? array_merge(
                $buckets['recommended'],
                $buckets[ImageModelInputLengthPolicy::TIER_FLASH],
                $buckets[ImageModelInputLengthPolicy::TIER_PRO],
                $buckets[ImageModelInputLengthPolicy::TIER_IMAGEN],
                $buckets[ImageModelInputLengthPolicy::TIER_OTHER],
            )
            : array_merge(
                $buckets['recommended'],
                $buckets[ImageModelInputLengthPolicy::TIER_PRO],
                $buckets[ImageModelInputLengthPolicy::TIER_FLASH],
                $buckets[ImageModelInputLengthPolicy::TIER_IMAGEN],
                $buckets[ImageModelInputLengthPolicy::TIER_OTHER],
            );

        return array_values(array_unique($ordered));
    }
}
