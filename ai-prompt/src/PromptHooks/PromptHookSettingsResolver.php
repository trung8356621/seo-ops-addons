<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks;

use Omnichannel\Addons\AiPrompt\PromptHooks\Data\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;

final class PromptHookSettingsResolver
{
    /**
     * Merge defaults + prompt override; drop unknown keys; clamp min/max.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public function resolve(PromptHookDefinition $definition, ?array $stored): array
    {
        $resolved = [];
        foreach ($definition->settings as $key => $schema) {
            if (! is_array($schema)) {
                continue;
            }

            $value = array_key_exists($key, $stored ?? [])
                ? $stored[$key]
                : ($schema['default'] ?? null);

            $resolved[$key] = $this->normalizeSettingValue($key, $value, $schema);
        }

        return $resolved;
    }

    /**
     * Khi đổi Hook: chỉ giữ key hợp lệ của manifest mới.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public function normalizeForDefinition(PromptHookDefinition $definition, ?array $stored): array
    {
        return $this->resolve($definition, $stored);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function normalizeSettingValue(string $key, mixed $value, array $schema): mixed
    {
        $type = (string) ($schema['type'] ?? 'string');

        return match ($type) {
            'integer', 'int' => $this->normalizeInteger($key, $value, $schema),
            'boolean', 'bool' => (bool) $value,
            'number', 'float' => $this->normalizeNumber($key, $value, $schema),
            default => is_string($value) ? $value : (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function normalizeInteger(string $key, mixed $value, array $schema): int
    {
        if ($value === null || $value === '') {
            $value = $schema['default'] ?? 0;
        }
        if (! is_numeric($value)) {
            throw new PromptHookException(
                PromptHookErrorCode::HookInputInvalid,
                "Hook setting [{$key}] must be an integer.",
            );
        }

        $int = (int) $value;
        if (isset($schema['min'])) {
            $int = max($int, (int) $schema['min']);
        }
        if (isset($schema['max'])) {
            $int = min($int, (int) $schema['max']);
        }

        return $int;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function normalizeNumber(string $key, mixed $value, array $schema): float
    {
        if ($value === null || $value === '') {
            $value = $schema['default'] ?? 0;
        }
        if (! is_numeric($value)) {
            throw new PromptHookException(
                PromptHookErrorCode::HookInputInvalid,
                "Hook setting [{$key}] must be a number.",
            );
        }

        $number = (float) $value;
        if (isset($schema['min'])) {
            $number = max($number, (float) $schema['min']);
        }
        if (isset($schema['max'])) {
            $number = min($number, (float) $schema['max']);
        }

        return $number;
    }
}
