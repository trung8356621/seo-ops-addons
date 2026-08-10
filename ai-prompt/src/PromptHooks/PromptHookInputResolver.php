<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks;

use Omnichannel\Addons\AiPrompt\PromptHooks\Data\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;

final class PromptHookInputResolver
{
    /**
     * Resolve → normalize → validate. Reject unknown runtime keys.
     *
     * @param  array<string, mixed>  $runtimeInput
     * @param  array<string, mixed>  $entityContext
     * @return array<string, mixed>
     */
    public function resolve(
        PromptHookDefinition $definition,
        array $runtimeInput,
        array $entityContext,
    ): array {
        $allowed = array_keys($definition->inputFields);
        foreach (array_keys($runtimeInput) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new PromptHookException(
                    PromptHookErrorCode::HookInputInvalid,
                    "Unknown input field [{$key}] for hook [{$definition->key}].",
                );
            }
        }

        $resolved = [];
        foreach ($definition->inputFields as $field => $schema) {
            if (! is_array($schema)) {
                continue;
            }

            $raw = $this->resolveFieldSources(
                field: $field,
                schema: $schema,
                runtimeInput: $runtimeInput,
                entityContext: $entityContext,
            );
            $resolved[$field] = $this->normalizeValue($raw, $schema['normalize'] ?? []);
        }

        $this->validateResolved($definition, $resolved);

        return $resolved;
    }

    /**
     * Fields exposed to PromptRunner (exclude expose_to_prompt === false).
     *
     * @param  array<string, mixed>  $resolvedInput
     * @return array<string, mixed>
     */
    public function exposeToPrompt(PromptHookDefinition $definition, array $resolvedInput): array
    {
        $exposed = [];
        foreach ($definition->promptPayload as $field) {
            $schema = $definition->inputFields[$field] ?? null;
            if (! is_array($schema)) {
                continue;
            }
            if (($schema['expose_to_prompt'] ?? true) === false) {
                continue;
            }
            $exposed[$field] = $resolvedInput[$field] ?? null;
        }

        return $exposed;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $runtimeInput
     * @param  array<string, mixed>  $entityContext
     */
    private function resolveFieldSources(
        string $field,
        array $schema,
        array $runtimeInput,
        array $entityContext,
    ): mixed {
        $sources = $schema['sources'] ?? null;
        if (! is_array($sources) || $sources === []) {
            // Backward-compatible: runtime then null.
            if (array_key_exists($field, $runtimeInput)) {
                return $runtimeInput[$field];
            }

            return null;
        }

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $type = (string) ($source['type'] ?? '');
            if ($type === 'runtime') {
                $path = (string) ($source['path'] ?? $field);
                if ($this->runtimeHasPath($runtimeInput, $path)) {
                    return $this->getPath($runtimeInput, $path);
                }

                continue;
            }

            if ($type === 'entity') {
                $path = (string) ($source['path'] ?? '');
                if ($path === '') {
                    continue;
                }
                if (! $this->pathExists($entityContext, $path)) {
                    continue;
                }
                $value = $this->getPath($entityContext, $path);
                // Null/empty entity → thử source tiếp (không coi là override tường minh).
                if ($value === null || $value === '') {
                    continue;
                }

                return $value;
            }

            if ($type === 'constant') {
                return $source['value'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function validateResolved(PromptHookDefinition $definition, array $resolved): void
    {
        foreach ($definition->inputFields as $field => $schema) {
            if (! is_array($schema)) {
                continue;
            }

            $value = $resolved[$field] ?? null;
            $required = (bool) ($schema['required'] ?? false);

            if ($required && ($value === null || $value === '')) {
                throw new PromptHookException(
                    PromptHookErrorCode::HookInputInvalid,
                    "Required input [{$field}] is missing for hook [{$definition->key}].",
                );
            }

            if ($value === null) {
                continue;
            }

            if (! $this->matchesType($value, $schema['type'] ?? 'string')) {
                throw new PromptHookException(
                    PromptHookErrorCode::HookInputInvalid,
                    "Input [{$field}] has invalid type for hook [{$definition->key}].",
                );
            }
        }
    }

    /**
     * @param  mixed  $type
     */
    private function matchesType(mixed $value, mixed $type): bool
    {
        $types = is_array($type) ? $type : [$type];
        foreach ($types as $candidate) {
            $candidate = (string) $candidate;
            $ok = match ($candidate) {
                'string' => is_string($value),
                'integer', 'int' => is_int($value) || (is_string($value) && ctype_digit($value)),
                'boolean', 'bool' => is_bool($value),
                'null' => $value === null,
                'number' => is_int($value) || is_float($value),
                default => true,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>|mixed  $steps
     */
    private function normalizeValue(mixed $value, mixed $steps): mixed
    {
        $steps = is_array($steps) ? $steps : [];
        foreach ($steps as $step) {
            $step = (string) $step;
            if ($step === 'trim' && is_string($value)) {
                $value = trim($value);
            }
            if ($step === 'empty_to_null' && (is_string($value) && $value === '' || $value === [])) {
                $value = null;
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function runtimeHasPath(array $data, string $path): bool
    {
        $segments = explode('.', $path);
        $cursor = $data;
        foreach ($segments as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return false;
            }
            $cursor = $cursor[$segment];
        }

        return true;
    }

    /**
     * Entity path: missing vs null — null value still "exists" if key present.
     *
     * @param  array<string, mixed>  $data
     */
    private function pathExists(array $data, string $path): bool
    {
        $segments = explode('.', $path);
        $cursor = $data;
        foreach ($segments as $index => $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return false;
            }
            $cursor = $cursor[$segment];
            // Last segment may be null — still counts as resolved (then next source if we want skip null?)
            // Spec: entity returns null description → use that null, don't fall through unless we skip empty.
            // For keyword missing, focus_keyword is null — should fall through to constant null, then validate fails if required.
            // So null from entity IS a resolved value. pathExists = true even if null.
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function getPath(array $data, string $path): mixed
    {
        $segments = explode('.', $path);
        $cursor = $data;
        foreach ($segments as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
