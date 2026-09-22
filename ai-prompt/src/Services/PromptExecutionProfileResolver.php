<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\Media\Support\ImageToolType;

/**
 * SSOT for Prompt Hook default execution profile + optional prompt-level override.
 *
 * Effective: prompt.routing_profile_key (when set) ?? hook map ?? tool default.
 * Model order / fallback still live in AI Center via AiModelRouterService.
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
        'seeding.comment.generate' => AiExecutionProfile::TextFast,
        'article.outline.generate' => AiExecutionProfile::TextReasoning,
        'article.outline.structure.generate' => AiExecutionProfile::TextReasoning,
        'article.vocabulary.generate' => AiExecutionProfile::TextReasoning,
        'keyword.discovery.structured' => AiExecutionProfile::TextLongform,
        'seo_audit.discover_new_topics' => AiExecutionProfile::TextReasoning,
        'seo_keywords.topical_map_audit' => AiExecutionProfile::TextReasoning,
        'article.content.generate' => AiExecutionProfile::TextLongform,
        'article.content.translate' => AiExecutionProfile::TextLongform,
        'article.content.improve' => AiExecutionProfile::TextLongform,
        'product.gallery.generate' => AiExecutionProfile::ImageProduct,
    ];

    public function resolve(?SeoPrompt $prompt, ?string $hookKey = null, ?string $toolType = null): AiExecutionProfile
    {
        $override = $this->overrideFromPrompt($prompt);
        if ($override !== null) {
            return $override;
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
     * Hook default only (ignores prompt override). Used by Prompt Admin reset UI.
     */
    public function hookDefault(?string $hookKey, ?string $toolType = 'default'): AiExecutionProfile
    {
        return $this->resolve(null, $hookKey, $toolType);
    }

    public function overrideFromPrompt(?SeoPrompt $prompt): ?AiExecutionProfile
    {
        if ($prompt === null) {
            return null;
        }

        $key = trim((string) ($prompt->routing_profile_key ?? ''));
        if ($key === '') {
            return null;
        }

        return AiExecutionProfile::tryFrom($key);
    }

    /**
     * @return array<string, string> value => display name
     */
    public function selectableProfileOptions(?string $hookKey = null, ?string $toolType = 'default'): array
    {
        $hookDefault = $this->hookDefault($hookKey, $toolType);
        $group = $hookDefault->group();
        $options = [];
        foreach (AiExecutionProfile::inGroup($group) as $profile) {
            $options[$profile->value] = $profile->displayName();
        }

        return $options;
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
