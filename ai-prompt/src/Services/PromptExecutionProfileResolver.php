<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingMode;
use Omnichannel\Addons\Media\Support\ImageToolType;

final class PromptExecutionProfileResolver
{
    /**
     * @var array<string, AiExecutionProfile>
     */
    private const HOOK_MAP = [
        'article.title_suggestion' => AiExecutionProfile::TextFast,
        'article.meta_description_suggestion' => AiExecutionProfile::TextFast,
        'article.faq.generate' => AiExecutionProfile::TextFast,
        'article.featured_snippet.generate' => AiExecutionProfile::TextFast,
        'article.comment.generate' => AiExecutionProfile::TextFast,
        'article.outline.generate' => AiExecutionProfile::TextReasoning,
        'keyword.discovery.structured' => AiExecutionProfile::TextReasoning,
        'article.content.generate' => AiExecutionProfile::TextLongform,
        'article.content.rewrite' => AiExecutionProfile::TextLongform,
        'article.content.translate' => AiExecutionProfile::TextLongform,
        'product.gallery.generate' => AiExecutionProfile::ImageProduct,
    ];

    public function resolve(?SeoPrompt $prompt, ?string $hookKey = null, ?string $toolType = null): AiExecutionProfile
    {
        $mode = AiRoutingMode::tryFrom((string) ($prompt?->routing_mode ?? '')) ?? AiRoutingMode::Auto;
        if ($mode === AiRoutingMode::Override) {
            $override = AiExecutionProfile::tryFrom(trim((string) ($prompt?->routing_profile_key ?? '')));
            if ($override !== null) {
                return $override;
            }
        }

        $hook = trim($hookKey ?? (string) ($prompt?->hook_key ?? ''));
        if ($hook !== '' && isset(self::HOOK_MAP[$hook])) {
            return self::HOOK_MAP[$hook];
        }

        $tool = ImageToolType::fromMixed($toolType ?? $prompt?->tools ?? 'default');

        return match ($tool) {
            ImageToolType::Image => AiExecutionProfile::ImageGeneral,
            ImageToolType::ImageTypography => AiExecutionProfile::ImageTypography,
            ImageToolType::Video => AiExecutionProfile::VideoGeneral,
            ImageToolType::Default => AiExecutionProfile::TextFast,
        };
    }

    /**
     * @return array<string, string>
     */
    public function hookMap(): array
    {
        $out = [];
        foreach (self::HOOK_MAP as $hook => $profile) {
            $out[$hook] = $profile->value;
        }

        return $out;
    }
}
