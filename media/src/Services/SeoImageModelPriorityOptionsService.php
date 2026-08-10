<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Omnichannel\Addons\Seo\Support\GeminiModelVersionPolicy;
use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;
use Omnichannel\Addons\Media\Support\ImageModelInputLengthPolicy;

final class SeoImageModelPriorityOptionsService
{
    /**
     * @return array<string, string> slug => label
     */
    public function imageModelSelectOptions(): array
    {
        $options = [];

        $models = SeoAiModel::query()
            ->where('category', AiModelCategory::IMAGEN_PRO)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->orderByDesc('priority')
            ->orderBy('raw_model_name')
            ->get(['raw_model_name', 'display_name']);

        foreach ($models as $model) {
            $slug = GoogleAiModelRegistry::normalizeSlug((string) $model->raw_model_name);
            if ($slug === '') {
                continue;
            }

            // Không đưa Gemini legacy (< 3) vào dropdown chọn priority runtime.
            if (! GeminiModelVersionPolicy::isEligibleForAutoRouting($slug)) {
                continue;
            }

            $label = trim((string) $model->display_name);
            $options[$slug] = $this->formatOptionLabel(
                $slug,
                $label !== '' ? $label.' ('.$slug.')' : $slug,
            );
        }

        foreach (GoogleAiModelRegistry::imageSelectOptions() as $slug => $label) {
            if (! GeminiModelVersionPolicy::isEligibleForAutoRouting((string) $slug)) {
                continue;
            }

            $options[$slug] ??= $this->formatOptionLabel((string) $slug, (string) $label);
        }

        return $options;
    }

    public function labelForSlug(string $slug): ?string
    {
        $slug = GoogleAiModelRegistry::normalizeSlug($slug);
        if ($slug === '') {
            return null;
        }

        $options = $this->imageModelSelectOptions();
        if (isset($options[$slug])) {
            return $options[$slug];
        }

        // Stored legacy slug: vẫn hiển thị kèm (Legacy) nếu còn trong DB form cũ.
        if (GeminiModelVersionPolicy::isGeminiFamily($slug)
            && ! GeminiModelVersionPolicy::meetsMinimumMajorVersion($slug)
        ) {
            return $slug.' · Legacy';
        }

        return $slug;
    }

    private function formatOptionLabel(string $slug, string $label): string
    {
        $tierHint = ImageModelInputLengthPolicy::tierHint(
            ImageModelInputLengthPolicy::tierForModel($slug),
        );

        if ($tierHint === '') {
            return $label;
        }

        return $label.' · '.$tierHint;
    }
}
