<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs;

/**
 * Validates Agent Pack manifest — fail closed, no executable fields.
 */
final class AgentPackManifestValidator
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return array{ok: bool, errors: list<string>, normalized?: array<string, mixed>}
     */
    public function validate(array $manifest, string $expectedType = 'custom'): array
    {
        $errors = [];

        $schema = (string) ($manifest['schema_version'] ?? '');
        if ($schema !== AgentPackConstants::SCHEMA_VERSION) {
            $errors[] = 'unsupported_schema:'.$schema;
        }

        $key = trim((string) ($manifest['key'] ?? ''));
        if ($key === '' || ! AgentPackConstants::isValidPackKey($key)) {
            $errors[] = 'invalid_key';
        }
        if ($this->looksLikeTraversalOrUrl($key)) {
            $errors[] = 'invalid_key_traversal';
        }
        if (str_starts_with($key, 'omi.') || str_starts_with($key, 'core.') || str_starts_with($key, 'agent.')) {
            if ($expectedType !== 'builtin') {
                $errors[] = 'core_namespace_takeover';
            }
        }

        $version = trim((string) ($manifest['version'] ?? ''));
        if (! AgentPackConstants::isValidSemver($version)) {
            $errors[] = 'invalid_semantic_version';
        }

        $name = trim((string) ($manifest['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'missing_name';
        }

        $type = trim((string) ($manifest['type'] ?? $expectedType));
        if (! in_array($type, AgentPackConstants::TYPES, true)) {
            $errors[] = 'invalid_type';
        }

        $sdkConstraint = $manifest['sdk_constraint'] ?? $manifest['sdk'] ?? null;
        if (! $this->sdkCompatible($sdkConstraint)) {
            $errors[] = 'sdk_incompatible';
        }

        $wsConstraint = $manifest['agent_workspace_constraint'] ?? $manifest['agent_workspace'] ?? null;
        if (! $this->workspaceCompatible($wsConstraint)) {
            $errors[] = 'workspace_incompatible';
        }

        if ($this->containsExecutableHints($manifest)) {
            $errors[] = 'executable_fields_forbidden';
        }

        $skills = $manifest['skills'] ?? [];
        if (! is_array($skills)) {
            $errors[] = 'skills_must_be_array';
            $skills = [];
        }

        $templates = $manifest['templates'] ?? [];
        if (! is_array($templates)) {
            $errors[] = 'templates_must_be_array';
            $templates = [];
        }

        $dependencies = $this->stringList($manifest['dependencies'] ?? []);
        $conflicts = $this->stringList($manifest['conflicts'] ?? []);

        foreach ($dependencies as $dep) {
            if (! AgentPackConstants::isValidPackKey($dep)) {
                $errors[] = 'invalid_dependency:'.$dep;
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return [
            'ok' => true,
            'errors' => [],
            'normalized' => [
                'schema_version' => AgentPackConstants::SCHEMA_VERSION,
                'key' => $key,
                'name' => $name,
                'version' => $version,
                'description' => trim((string) ($manifest['description'] ?? '')),
                'provider' => trim((string) ($manifest['provider'] ?? 'studio')),
                'sdk_constraint' => AgentPackConstants::SDK_MAJOR,
                'agent_workspace_constraint' => AgentPackConstants::WORKSPACE_VERSION,
                'type' => $type,
                'skills' => array_values($skills),
                'templates' => array_values($templates),
                'translations' => is_array($manifest['translations'] ?? null) ? $manifest['translations'] : [],
                'evaluation_datasets' => is_array($manifest['evaluation_datasets'] ?? null)
                    ? $manifest['evaluation_datasets']
                    : [],
                'permissions' => is_array($manifest['permissions'] ?? null) ? $manifest['permissions'] : [],
                'dependencies' => $dependencies,
                'conflicts' => $conflicts,
                'metadata' => is_array($manifest['metadata'] ?? null) ? $manifest['metadata'] : [],
            ],
        ];
    }

    private function sdkCompatible(mixed $constraint): bool
    {
        if ($constraint === null || $constraint === '') {
            return true;
        }
        if (is_int($constraint) || (is_string($constraint) && ctype_digit($constraint))) {
            return (int) $constraint === AgentPackConstants::SDK_MAJOR;
        }
        if (is_string($constraint)) {
            if (preg_match('/^\^?(\d+)/', $constraint, $m)) {
                return (int) $m[1] === AgentPackConstants::SDK_MAJOR;
            }
        }

        return false;
    }

    private function workspaceCompatible(mixed $constraint): bool
    {
        if ($constraint === null || $constraint === '') {
            return true;
        }
        $raw = trim((string) $constraint);
        if ($raw === '*' || $raw === AgentPackConstants::WORKSPACE_VERSION) {
            return true;
        }
        if (preg_match('/^\^?(\d+)/', $raw, $m)) {
            return (int) $m[1] === 7;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function containsExecutableHints(array $manifest): bool
    {
        $blob = strtolower(json_encode($manifest) ?: '');
        $needles = [
            '<?php',
            'eval(',
            'shell_exec',
            'passthru',
            'proc_open',
            'system(',
            'javascript:',
            'data:text/html',
            '__halt_compiler',
            'create_function',
        ];
        foreach ($needles as $n) {
            if (str_contains($blob, $n)) {
                return true;
            }
        }

        return isset($manifest['php'])
            || isset($manifest['handlers'])
            || isset($manifest['class'])
            || isset($manifest['entrypoint']);
    }

    private function looksLikeTraversalOrUrl(string $key): bool
    {
        return str_contains($key, '..')
            || str_contains($key, '/')
            || str_contains($key, '\\')
            || str_contains($key, '://')
            || str_contains($key, '@');
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            $value,
        ), static fn (string $v): bool => $v !== ''));
    }
}
