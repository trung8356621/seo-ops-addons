<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use InvalidArgumentException;

/** Versioned hook binding persisted on SeoPrompt (editor selection). */
final class PromptHookBinding
{
    public function __construct(
        public readonly string $hookKey,
        public readonly string $hookVersion,
    ) {}

    public static function tryFromPrompt(SeoPrompt $prompt): ?self
    {
        $key = trim((string) ($prompt->hook_key ?? ''));
        if ($key === '') {
            return null;
        }

        $version = self::normalizeVersion($prompt->hook_version ?? null);
        if ($version === '') {
            throw new InvalidArgumentException(
                "Prompt #{$prompt->id} has hook_key [{$key}] but missing hook_version.",
            );
        }

        return new self($key, $version);
    }

    public static function normalizeVersion(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
            $int = (int) $value;

            // Phase 1 stored integer 1 → Spec experimental pin 0.1.0
            return $int === 1 ? '0.1.0' : (string) $int;
        }

        return trim((string) $value);
    }

    /**
     * @return array{hook_key: string, hook_version: string}
     */
    public function toArray(): array
    {
        return [
            'hook_key' => $this->hookKey,
            'hook_version' => $this->hookVersion,
        ];
    }
}
