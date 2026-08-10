<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs;

/**
 * Safe input/output mapping validation + apply (no expression eval).
 */
final class AgentPackSafeMappingValidator
{
    /**
     * @param  array<string, mixed>  $mapping  field => source|array{source, transform?}
     * @return array{ok: bool, errors: list<string>}
     */
    public function validateInputMapping(array $mapping): array
    {
        $errors = [];
        foreach ($mapping as $target => $spec) {
            if (! is_string($target) || $target === '' || preg_match('/[^a-z0-9_]/', $target)) {
                $errors[] = 'mapping.invalid_target:'.$target;

                continue;
            }
            $source = is_array($spec) ? (string) ($spec['source'] ?? '') : (string) $spec;
            if (! $this->isAllowedSource($source)) {
                $errors[] = 'mapping.unsafe_source:'.$source;
            }
            if (is_array($spec)) {
                $transforms = $spec['transforms'] ?? ($spec['transform'] ?? []);
                if (is_string($transforms)) {
                    $transforms = [$transforms];
                }
                if (! is_array($transforms)) {
                    $errors[] = 'mapping.bad_transforms:'.$target;

                    continue;
                }
                foreach ($transforms as $t) {
                    if (! in_array((string) $t, AgentPackConstants::SAFE_TRANSFORMERS, true)) {
                        $errors[] = 'mapping.unsafe_transformer:'.$t;
                    }
                }
            }
            if ($this->looksSecret($source) || $this->looksSecret((string) $target)) {
                $errors[] = 'mapping.secret_forbidden:'.$target;
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * Output mapping may only read Gateway result payload paths ($result.*).
     *
     * @param  array<string, mixed>  $mapping
     * @return array{ok: bool, errors: list<string>}
     */
    public function validateOutputMapping(array $mapping): array
    {
        $errors = [];
        foreach ($mapping as $target => $spec) {
            $source = is_array($spec) ? (string) ($spec['source'] ?? '') : (string) $spec;
            if (! str_starts_with($source, '$result.')) {
                $errors[] = 'output_mapping.gateway_payload_only:'.$source;
            }
            if ($this->looksSecret($source) || $this->looksSecret((string) $target)) {
                $errors[] = 'output_mapping.secret_forbidden';
            }
            if (is_array($spec)) {
                $transforms = $spec['transforms'] ?? ($spec['transform'] ?? []);
                if (is_string($transforms)) {
                    $transforms = [$transforms];
                }
                foreach ((array) $transforms as $t) {
                    if (! in_array((string) $t, AgentPackConstants::SAFE_TRANSFORMERS, true)) {
                        $errors[] = 'output_mapping.unsafe_transformer:'.$t;
                    }
                }
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $actor
     * @param  array<string, mixed>  $execution
     * @return array<string, mixed>
     */
    public function applyInputMapping(
        array $mapping,
        array $input,
        array $context = [],
        array $actor = [],
        array $execution = [],
    ): array {
        $out = [];
        foreach ($mapping as $target => $spec) {
            $source = is_array($spec) ? (string) ($spec['source'] ?? '') : (string) $spec;
            $value = $this->resolveSource($source, $input, $context, $actor, $execution);
            $transforms = [];
            if (is_array($spec)) {
                $transforms = $spec['transforms'] ?? ($spec['transform'] ?? []);
                if (is_string($transforms)) {
                    $transforms = [$transforms];
                }
            }
            foreach ((array) $transforms as $t) {
                $value = $this->transform($value, (string) $t, is_array($spec) ? $spec : []);
            }
            $out[(string) $target] = $value;
        }

        return $out;
    }

    private function isAllowedSource(string $source): bool
    {
        if ($source === '$actor.id') {
            return true;
        }
        foreach (AgentPackConstants::INPUT_SOURCE_PREFIXES as $prefix) {
            if ($prefix === '$input.' && str_starts_with($source, '$input.')) {
                $path = substr($source, strlen('$input.'));

                return $path !== '' && (bool) preg_match('/^[a-z0-9_]+(?:\.[a-z0-9_]+)*$/', $path);
            }
            if ($prefix === '$execution.' && str_starts_with($source, '$execution.')) {
                return (bool) preg_match('/^\$execution\.[a-z0-9_]+$/', $source);
            }
            if ($source === $prefix || str_starts_with($source, rtrim($prefix, '.'))) {
                if (in_array($source, [
                    '$context.connection_hash',
                    '$context.project_ref',
                    '$context.workspace_ref',
                    '$context.article_ref',
                    '$actor.id',
                ], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $actor
     * @param  array<string, mixed>  $execution
     */
    private function resolveSource(
        string $source,
        array $input,
        array $context,
        array $actor,
        array $execution,
    ): mixed {
        if (str_starts_with($source, '$input.')) {
            return $this->pathGet($input, substr($source, 7));
        }

        return match ($source) {
            '$context.connection_hash' => $context['connection_hash'] ?? null,
            '$context.project_ref' => $context['project_ref'] ?? null,
            '$context.workspace_ref' => $context['workspace_ref'] ?? null,
            '$context.article_ref' => $context['article_ref'] ?? null,
            '$actor.id' => $actor['id'] ?? null,
            default => str_starts_with($source, '$execution.')
                ? ($execution[substr($source, 11)] ?? null)
                : null,
        };
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function transform(mixed $value, string $name, array $spec): mixed
    {
        return match ($name) {
            'trim' => is_string($value) ? trim($value) : $value,
            'lowercase' => is_string($value) ? mb_strtolower($value) : $value,
            'uppercase' => is_string($value) ? mb_strtoupper($value) : $value,
            'integer' => is_numeric($value) ? (int) $value : $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value,
            'date_format' => is_string($value)
                ? (date((string) ($spec['format'] ?? 'Y-m-d'), strtotime($value) ?: time()) ?: $value)
                : $value,
            'unique_array' => is_array($value) ? array_values(array_unique($value)) : $value,
            'remove_empty' => is_array($value)
                ? array_values(array_filter($value, static fn (mixed $v): bool => $v !== null && $v !== ''))
                : ($value === '' ? null : $value),
            default => $value,
        };
    }

    private function looksSecret(string $s): bool
    {
        $l = strtolower($s);

        return str_contains($l, 'secret')
            || str_contains($l, 'password')
            || str_contains($l, 'api_key')
            || str_contains($l, 'token')
            || str_contains($l, 'env.')
            || str_contains($l, 'config.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function pathGet(array $data, string $path): mixed
    {
        $cursor = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
