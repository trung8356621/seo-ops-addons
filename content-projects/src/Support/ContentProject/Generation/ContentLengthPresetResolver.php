<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation;

use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;

/**
 * Turns a per-item length preset into a concrete word target.
 *
 * Presets are relative to the domain-wide target from Prompt settings, so raising
 * the domain target moves every preset with it. A null mode means "inherit" and
 * deliberately yields null — the caller must not stamp article_length at all.
 */
final class ContentLengthPresetResolver
{
    public const MULTIPLIER_SHORT = 0.5;

    public const MULTIPLIER_STANDARD = 1.0;

    public const MULTIPLIER_LONG = 1.5;

    public function __construct(
        private readonly SeoPromptSettingsService $promptSettings,
    ) {}

    public function resolveTargetWords(
        ?ItemContentLengthMode $mode,
        ?string $postType = null,
        ?int $customWords = null,
    ): ?int {
        if ($mode === null) {
            return null;
        }

        if ($mode === ItemContentLengthMode::Custom) {
            return $customWords !== null && $customWords > 0 ? $customWords : null;
        }

        $base = $this->promptSettings->resolveArticleLengthTarget($postType);

        return max(1, (int) round($base * self::multiplierFor($mode)));
    }

    public function baseTargetWords(?string $postType = null): int
    {
        return $this->promptSettings->resolveArticleLengthTarget($postType);
    }

    public static function multiplierFor(ItemContentLengthMode $mode): float
    {
        return match ($mode) {
            ItemContentLengthMode::Short => self::MULTIPLIER_SHORT,
            ItemContentLengthMode::Long => self::MULTIPLIER_LONG,
            ItemContentLengthMode::Standard, ItemContentLengthMode::Custom => self::MULTIPLIER_STANDARD,
        };
    }
}
