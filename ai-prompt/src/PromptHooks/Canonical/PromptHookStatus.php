<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Canonical;

enum PromptHookStatus: string
{
    case Experimental = 'experimental';
    case Stable = 'stable';
    case Deprecated = 'deprecated';
    case Disabled = 'disabled';

    public static function fromMixed(mixed $value): self
    {
        $raw = is_string($value) ? strtolower(trim($value)) : '';

        return match ($raw) {
            self::Stable->value => self::Stable,
            self::Deprecated->value => self::Deprecated,
            self::Disabled->value => self::Disabled,
            default => self::Experimental,
        };
    }

    public function allowsExecution(): bool
    {
        return $this !== self::Disabled;
    }
}
