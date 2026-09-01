<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation;

use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGenerationKeyword;

/**
 * Canonical resolver for the per-item generation policy.
 *
 * Tone precedence is intentionally item-scoped: the legacy site-level tone is never
 * consulted here. Without an explicit tone_override the item gets Automatic variety,
 * stickied through resolved_tone so reruns stay stable.
 *
 * Every column is read from the raw attribute bag, so tasks loaded before the
 * policy migration ran resolve to the same defaults instead of blowing up.
 */
final class ContentProjectItemGenerationPolicyResolver
{
    public function __construct(
        private readonly AutomaticVarietyToneResolver $toneResolver,
        private readonly ContentLengthPresetResolver $lengthResolver,
    ) {}

    public static function withPromptSettings(SeoPromptSettingsService $promptSettings): self
    {
        return new self(
            new AutomaticVarietyToneResolver($promptSettings),
            new ContentLengthPresetResolver($promptSettings),
        );
    }

    public function resolve(SeoProjectTask $task): ContentProjectItemGenerationPolicy
    {
        $postType = self::rawString($task, 'post_type');
        $toneOverride = self::rawString($task, 'tone_override');
        $stickyTone = self::rawString($task, 'resolved_tone');

        $toneIsAutomaticVariety = $toneOverride === null;
        $toneWasSticky = false;

        if ($toneOverride !== null) {
            $tone = $toneOverride;
        } elseif ($stickyTone !== null) {
            $tone = $stickyTone;
            $toneWasSticky = true;
        } else {
            $tone = $this->toneResolver->resolve(
                self::rawInt($task, $task->getKeyName()),
                ContentProjectGenerationKeyword::effective($task),
                $postType,
                self::rawString($task, 'secondary_description') ?? self::rawString($task, 'description') ?? '',
            );
        }

        $contentLengthMode = ItemContentLengthMode::tryFromMixed(self::rawString($task, 'content_length_override'));
        $customWords = self::rawInt($task, 'content_length_target_words');

        $modelOverrideId = self::rawInt($task, 'model_override_id');
        $modelOverrideMode = $modelOverrideId === null
            ? null
            : ItemModelOverrideMode::tryFromMixed(self::rawString($task, 'model_override_mode'))
                ?? ItemModelOverrideMode::Preferred;

        $titleProtection = ItemTitleProtection::tryFromMixed(self::rawString($task, 'title_protection'));
        $hasTitle = self::rawString($task, 'title') !== null;

        return new ContentProjectItemGenerationPolicy(
            tone: $tone,
            toneIsAutomaticVariety: $toneIsAutomaticVariety,
            toneWasSticky: $toneWasSticky,
            contentLengthMode: $contentLengthMode,
            contentLengthTargetWords: $this->lengthResolver->resolveTargetWords(
                $contentLengthMode,
                $postType,
                $customWords,
            ),
            generationMode: ItemGenerationMode::tryFromMixed(self::rawString($task, 'generation_mode_override')),
            modelOverrideId: $modelOverrideId,
            modelOverrideMode: $modelOverrideMode,
            titleProtection: $titleProtection,
            protectTitle: $titleProtection !== null || $hasTitle,
        );
    }

    /**
     * Read straight from the attribute bag: a column missing on legacy rows must
     * behave exactly like a null column, never as a relation lookup.
     */
    private static function rawString(SeoProjectTask $task, string $key): ?string
    {
        $attributes = $task->getAttributes();
        if (! array_key_exists($key, $attributes)) {
            return null;
        }

        $value = $attributes[$key];
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private static function rawInt(SeoProjectTask $task, string $key): ?int
    {
        $raw = self::rawString($task, $key);
        if ($raw === null || ! is_numeric($raw)) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }
}
