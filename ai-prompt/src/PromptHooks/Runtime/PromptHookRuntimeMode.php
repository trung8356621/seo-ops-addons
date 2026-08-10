<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

enum PromptHookRuntimeMode: string
{
    case Legacy = 'legacy';
    case Shadow = 'shadow';
    case Hook = 'hook';

    public static function fromConfig(mixed $value): self
    {
        $raw = is_string($value) ? strtolower(trim($value)) : '';

        return match ($raw) {
            self::Shadow->value => self::Shadow,
            self::Hook->value => self::Hook,
            default => self::Legacy,
        };
    }
}
