<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\Media\Support\ImageToolType;

/**
 * SSOT for modern Prompt Hook → execution profile.
 *
 * Prompt DB fields (routing_mode, routing_profile_key) must NOT override this map.
 * Model order / fallback live in AI Center via AiModelRouterService.
 */
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
        'article.outline.structure.generate' => AiExecutionProfile::TextReasoning,
        'article.vocabulary.generate' => AiExecutionProfile::TextReasoning,
        'keyword.discovery.structured' => AiExecutionProfile::TextReasoning,
        'article.content.generate' => AiExecutionProfile::TextLongform,
        'article.content.rewrite' => AiExecutionProfile::TextLongform,
        'article.content.translate' => AiExecutionProfile::TextLongform,
        'article.content.improve' => AiExecutionProfile::TextLongform,
        'product.gallery.generate' => AiExecutionProfile::ImageProduct,
    ];

    public function resolve(?SeoPrompt $prompt, ?string $hookKey = null, ?string $toolType = null): AiExecutionProfile
    {
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
