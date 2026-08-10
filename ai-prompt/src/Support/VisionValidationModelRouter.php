<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\Media\Support\ImageCapability;
use Omnichannel\Addons\Media\Support\ImageCapabilityResolver;
use Omnichannel\Addons\Seo\Support\GeminiModelVersionPolicy;
use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;

/**
 * Candidate list Vision validation — capability + min version, không hard-code 1 model.
 */
final class VisionValidationModelRouter
{
    /**
     * Ưu tiên theo thứ tự: preferred (nếu hợp lệ) → 3.5 flash → text Gemini >= 3 còn lại.
     *
     * @return list<string>
     */
    public function modelsToTry(?string $preferredModel = null): array
    {
        $resolver = new ImageCapabilityResolver();
        $ordered = [];

        $preferred = GoogleAiModelRegistry::normalizeSlug(trim((string) $preferredModel));
        if ($preferred !== '' && $this->isEligibleVisionModel($preferred, $resolver)) {
            $ordered[] = $preferred;
        }

        foreach ($this->preferredVisionSlugs() as $slug) {
            if ($this->isEligibleVisionModel($slug, $resolver)) {
                $ordered[] = $slug;
            }
        }

        $rest = [];
        foreach (array_keys(GoogleAiModelRegistry::textSelectOptions()) as $slug) {
            $slug = (string) $slug;
            if ($this->isEligibleVisionModel($slug, $resolver)) {
                $rest[] = $slug;
            }
        }

        $rest = GeminiModelVersionPolicy::filterEligibleForAutoRouting($rest);
        $rest = GeminiModelVersionPolicy::preferStableFirst($rest);

        return array_values(array_unique(array_merge($ordered, $rest)));
    }

    public function resolvePrimary(?string $preferredModel = null): string
    {
        $list = $this->modelsToTry($preferredModel);
        if ($list === []) {
            return 'gemini-3.5-flash-preview';
        }

        return $list[0];
    }

    /**
     * @return list<string>
     */
    private function preferredVisionSlugs(): array
    {
        return [
            'gemini-3.5-flash-preview',
            'gemini-3-flash-preview',
            'gemini-3.1-flash-lite',
            'gemini-3.1-pro-preview',
        ];
    }

    private function isEligibleVisionModel(string $slug, ImageCapabilityResolver $resolver): bool
    {
        if (! GeminiModelVersionPolicy::meetsMinimumMajorVersion($slug)) {
            return false;
        }

        if (GeminiModelVersionPolicy::isDeprecatedOrShutdown($slug)) {
            return false;
        }

        $capabilities = $resolver->resolve($slug);
        $hasText = in_array(ImageCapability::TextGeneration->value, $capabilities, true);
        $hasImageInput = in_array(ImageCapability::ImageInput->value, $capabilities, true);
        $hasImageGeneration = in_array(ImageCapability::ImageGeneration->value, $capabilities, true);

        // Không dùng model image-generation-only (Nano Banana / Imagen).
        if ($hasImageGeneration && ! $hasText) {
            return false;
        }

        return $hasText && $hasImageInput;
    }
}
