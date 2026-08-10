<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support;

use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;

/**
 * Resolve capability model — registry nội bộ trước, heuristic sau, unknown cuối.
 */
final class ImageCapabilityResolver
{
    /**
     * @param  array<string, mixed>|null  $storedCapabilities  seo_ai_models.capabilities
     * @return list<string>
     */
    public function resolve(string $modelSlug, ?array $storedCapabilities = null): array
    {
        $fromStored = $this->fromStoredResolved($storedCapabilities);
        if ($fromStored !== null) {
            return $fromStored;
        }

        $fromRegistry = $this->fromInternalRegistry($modelSlug);
        if ($fromRegistry !== null) {
            return $fromRegistry;
        }

        return $this->fromHeuristic($modelSlug);
    }

    /**
     * Payload ghi vào capabilities.resolved khi sync.
     *
     * @param  array<string, mixed>|null  $existingCapabilities
     * @return array<string, mixed>
     */
    public function mergeResolvedIntoCapabilities(string $modelSlug, ?array $existingCapabilities = null): array
    {
        $capabilities = is_array($existingCapabilities) ? $existingCapabilities : [];
        $capabilities['resolved'] = $this->resolve($modelSlug, null);

        return $capabilities;
    }

    public function hasCapability(string $modelSlug, string $capability, ?array $storedCapabilities = null): bool
    {
        return in_array($capability, $this->resolve($modelSlug, $storedCapabilities), true);
    }

    public function isUnknown(string $modelSlug, ?array $storedCapabilities = null): bool
    {
        $resolved = $this->resolve($modelSlug, $storedCapabilities);

        return $resolved === [ImageCapability::Unknown->value]
            || (in_array(ImageCapability::Unknown->value, $resolved, true) && ! in_array(ImageCapability::ImageGeneration->value, $resolved, true) && ! in_array(ImageCapability::TextGeneration->value, $resolved, true) && ! in_array(ImageCapability::VideoGeneration->value, $resolved, true));
    }

    /**
     * @param  array<string, mixed>|null  $storedCapabilities
     * @return list<string>|null
     */
    private function fromStoredResolved(?array $storedCapabilities): ?array
    {
        if ($storedCapabilities === null) {
            return null;
        }

        $resolved = $storedCapabilities['resolved'] ?? null;
        if (! is_array($resolved) || $resolved === []) {
            return null;
        }

        $normalized = [];
        foreach ($resolved as $item) {
            $value = trim((string) $item);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return $normalized !== [] ? array_values(array_unique($normalized)) : null;
    }

    /**
     * @return list<string>|null
     */
    private function fromInternalRegistry(string $modelSlug): ?array
    {
        $slug = GoogleAiModelRegistry::normalizeSlug($modelSlug);
        if ($slug === '') {
            return [ImageCapability::Unknown->value];
        }

        if (! GoogleAiModelRegistry::isRegistered($slug)) {
            return null;
        }

        $category = GoogleAiModelRegistry::categoryOf($slug);

        return match ($category) {
            GoogleAiModelRegistry::CATEGORY_TEXT => [
                ImageCapability::TextGeneration->value,
                ImageCapability::ImageInput->value,
            ],
            GoogleAiModelRegistry::CATEGORY_VIDEO => [
                ImageCapability::VideoGeneration->value,
            ],
            GoogleAiModelRegistry::CATEGORY_IMAGE_IMAGEN => [
                ImageCapability::ImageGeneration->value,
                ImageCapability::GeneralImage->value,
            ],
            GoogleAiModelRegistry::CATEGORY_IMAGE_GEMINI => $this->geminiImageCapabilities($slug),
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function geminiImageCapabilities(string $slug): array
    {
        $base = [
            ImageCapability::ImageGeneration->value,
            ImageCapability::GeneralImage->value,
            ImageCapability::TypographySupported->value,
        ];

        $isPro = preg_match('/(?:^|-)pro(?:-|$)/', $slug) === 1
            || str_contains($slug, '-pro-image')
            || str_contains($slug, 'pro-image');

        if ($isPro) {
            $base[] = ImageCapability::TypographyRecommended->value;
        }

        return array_values(array_unique($base));
    }

    /**
     * @return list<string>
     */
    private function fromHeuristic(string $modelSlug): array
    {
        $slug = strtolower(trim($modelSlug));
        if ($slug === '') {
            return [ImageCapability::Unknown->value];
        }

        if (str_contains($slug, 'embedding') || str_contains($slug, 'tts') || str_contains($slug, 'lyria')) {
            return [ImageCapability::Unknown->value];
        }

        if (str_contains($slug, 'veo') || str_contains($slug, 'video')) {
            return [ImageCapability::VideoGeneration->value];
        }

        if (str_contains($slug, 'imagen')) {
            return [
                ImageCapability::ImageGeneration->value,
                ImageCapability::GeneralImage->value,
            ];
        }

        if (str_contains($slug, 'image') || str_contains($slug, 'banana')) {
            return $this->geminiImageCapabilities($slug);
        }

        if (str_contains($slug, 'gemini') || str_contains($slug, 'claude') || str_contains($slug, 'gpt')) {
            return [
                ImageCapability::TextGeneration->value,
                ImageCapability::ImageInput->value,
            ];
        }

        return [ImageCapability::Unknown->value];
    }
}
