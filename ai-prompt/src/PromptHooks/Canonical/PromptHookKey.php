<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Canonical;

/** Immutable hook key: module.resource_or_capability[.verb...] */
final class PromptHookKey
{
    private const PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    public readonly string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);
        if ($trimmed === '' || preg_match(self::PATTERN, $trimmed) !== 1) {
            throw new \InvalidArgumentException("Invalid prompt hook key [{$value}]");
        }
        $this->value = $trimmed;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
