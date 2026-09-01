<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation;

/**
 * Resolved per-item generation policy — the single answer to
 * "which tone, how long, which model, and may the title be rewritten?".
 */
final class ContentProjectItemGenerationPolicy
{
    public function __construct(
        public readonly ?string $tone,
        public readonly bool $toneIsAutomaticVariety,
        public readonly bool $toneWasSticky,
        public readonly ?ItemContentLengthMode $contentLengthMode,
        public readonly ?int $contentLengthTargetWords,
        public readonly ?ItemGenerationMode $generationMode,
        public readonly ?int $modelOverrideId,
        public readonly ?ItemModelOverrideMode $modelOverrideMode,
        public readonly ?ItemTitleProtection $titleProtection,
        public readonly bool $protectTitle,
    ) {}

    public function hasToneOverride(): bool
    {
        return ! $this->toneIsAutomaticVariety && $this->tone !== null;
    }

    /** No length preset chosen — generation keeps the domain-wide target. */
    public function inheritsContentLength(): bool
    {
        return $this->contentLengthMode === null;
    }

    public function hasModelOverride(): bool
    {
        return $this->modelOverrideId !== null;
    }

    public function requiresModelOverride(): bool
    {
        return $this->hasModelOverride() && $this->modelOverrideMode === ItemModelOverrideMode::Required;
    }

    /**
     * Automatic variety picked a fresh tone that is not stickied yet.
     * Persisting it keeps reruns of the same item on the same tone.
     */
    public function shouldPersistResolvedTone(): bool
    {
        return $this->toneIsAutomaticVariety
            && ! $this->toneWasSticky
            && $this->tone !== null
            && $this->tone !== '';
    }

    /**
     * Protection actually applied at generation time. A filled title with no stored
     * protection is treated as user-owned.
     */
    public function effectiveTitleProtection(): ?ItemTitleProtection
    {
        if ($this->titleProtection !== null) {
            return $this->titleProtection;
        }

        return $this->protectTitle ? ItemTitleProtection::User : null;
    }

    /** Badge count for the item row — how many defaults the operator moved away from. */
    public function countOverrides(): int
    {
        $count = 0;

        if ($this->hasToneOverride()) {
            $count++;
        }

        if ($this->contentLengthMode !== null) {
            $count++;
        }

        if ($this->generationMode !== null) {
            $count++;
        }

        if ($this->hasModelOverride()) {
            $count++;
        }

        return $count;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tone' => $this->tone,
            'tone_is_automatic_variety' => $this->toneIsAutomaticVariety,
            'tone_was_sticky' => $this->toneWasSticky,
            'content_length_mode' => $this->contentLengthMode?->value,
            'content_length_target_words' => $this->contentLengthTargetWords,
            'generation_mode' => $this->generationMode?->value,
            'model_override_id' => $this->modelOverrideId,
            'model_override_mode' => $this->modelOverrideMode?->value,
            'title_protection' => $this->titleProtection?->value,
            'effective_title_protection' => $this->effectiveTitleProtection()?->value,
            'protect_title' => $this->protectTitle,
            'override_count' => $this->countOverrides(),
        ];
    }
}
