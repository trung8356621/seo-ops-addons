<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs;

/**
 * Compiles validated manifest into skill/template/localization artifacts.
 * Compile failure → zero partial skills.
 */
final class AgentPackCompiler
{
    public function __construct(
        private readonly AgentPackManifestValidator $manifests = new AgentPackManifestValidator,
        private readonly AgentPackCapabilityBinder $binder,
        private readonly AgentPackCompatibilityService $compatibility = new AgentPackCompatibilityService,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, array{status: string, version?: string, dependencies?: list<string>}>  $knownPacks
     * @param  list<string>  $occupiedCommands
     * @param  list<string>  $occupiedSkillKeys
     * @return array{ok: bool, errors: list<string>, compiled?: array<string, mixed>, revision_hash?: string}
     */
    public function compile(
        array $manifest,
        array $knownPacks = [],
        array $occupiedCommands = [],
        array $occupiedSkillKeys = [],
        string $expectedType = 'custom',
    ): array {
        $validated = $this->manifests->validate($manifest, $expectedType);
        if (! $validated['ok']) {
            return ['ok' => false, 'errors' => $validated['errors']];
        }
        /** @var array<string, mixed> $norm */
        $norm = $validated['normalized'];

        $compat = $this->compatibility->check($norm, $knownPacks);
        if (! $compat['ok']) {
            return ['ok' => false, 'errors' => $compat['errors']];
        }

        $skills = [];
        $commands = $occupiedCommands;
        $keys = $occupiedSkillKeys;
        $errors = [];

        foreach ($norm['skills'] as $skill) {
            if (! is_array($skill)) {
                $errors[] = 'skill.invalid_row';

                continue;
            }
            $bound = $this->binder->bind($skill, $commands, $keys, (string) $norm['key']);
            if (! $bound['ok']) {
                $errors = array_merge($errors, $bound['errors']);

                continue;
            }
            /** @var array<string, mixed> $compiled */
            $compiled = $bound['compiled'];
            $skills[] = $compiled;
            $keys[] = (string) $compiled['key'];
            $commands[] = (string) $compiled['slash_command'];
            foreach ($compiled['aliases'] as $alias) {
                $commands[] = (string) $alias;
            }
        }

        $templates = [];
        foreach ($norm['templates'] as $tpl) {
            if (! is_array($tpl)) {
                $errors[] = 'template.invalid_row';

                continue;
            }
            $tCheck = $this->validateTemplate($tpl, array_column($skills, 'key'));
            if (! $tCheck['ok']) {
                $errors = array_merge($errors, $tCheck['errors']);

                continue;
            }
            $templates[] = $tCheck['compiled'];
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $compiled = [
            'manifest' => $norm,
            'skills' => $skills,
            'templates' => $templates,
            'translations' => $norm['translations'],
            'planning_catalog' => array_map(static fn (array $s): array => [
                'key' => $s['key'],
                'slash_command' => $s['slash_command'],
                'capability' => $s['capability'],
                'confirmation' => $s['confirmation_policy'],
                'planning' => $s['planning_metadata'],
            ], $skills),
            'automation_metadata' => array_map(static fn (array $s): array => [
                'key' => $s['key'],
                'automation' => $s['automation_metadata'],
                'confirmation' => $s['confirmation_policy'],
            ], $skills),
            'evaluation_datasets' => $this->namespaceDatasets(
                (string) $norm['key'],
                is_array($norm['evaluation_datasets']) ? $norm['evaluation_datasets'] : [],
            ),
            'load_order' => $compat['order'] ?? [(string) $norm['key']],
        ];

        $hash = hash('sha256', json_encode($compiled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        return [
            'ok' => true,
            'errors' => [],
            'compiled' => $compiled,
            'revision_hash' => $hash,
        ];
    }

    /**
     * @param  array<string, mixed>  $tpl
     * @param  list<string>  $skillKeys
     * @return array{ok: bool, errors: list<string>, compiled?: array<string, mixed>}
     */
    private function validateTemplate(array $tpl, array $skillKeys): array
    {
        $errors = [];
        $key = trim((string) ($tpl['key'] ?? ''));
        if ($key === '') {
            $errors[] = 'template.missing_key';
        }
        if (isset($tpl['execute']) || isset($tpl['confirm']) || isset($tpl['capability'])) {
            $errors[] = 'template.execution_forbidden';
        }
        if (isset($tpl['hidden_instructions']) || isset($tpl['system_prompt'])) {
            $errors[] = 'template.hidden_tool_instructions';
        }
        $opens = (string) ($tpl['open_skill'] ?? $tpl['skill_key'] ?? '');
        if ($opens !== '' && $skillKeys !== [] && ! in_array($opens, $skillKeys, true)) {
            // May open builtin skill — allow non-pack keys without fail if not in pack skills.
        }
        $prefill = is_array($tpl['prefill'] ?? null) ? $tpl['prefill'] : [];
        foreach (array_keys($prefill) as $field) {
            if (in_array(strtolower((string) $field), ['site_id', 'owner_id', 'tenant_id'], true)) {
                $errors[] = 'template.site_override_forbidden';
            }
        }
        $vars = is_array($tpl['variables'] ?? null) ? $tpl['variables'] : [];
        $allowed = ['current_date', 'current_month', 'next_month', 'site_name', 'site_domain', 'project_ref', 'workspace_ref'];
        foreach ($vars as $v) {
            if (! in_array((string) $v, $allowed, true)) {
                $errors[] = 'template.bad_variable:'.$v;
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return [
            'ok' => true,
            'errors' => [],
            'compiled' => [
                'key' => $key,
                'name' => (string) ($tpl['name'] ?? $key),
                'description' => (string) ($tpl['description'] ?? ''),
                'open_skill' => $opens,
                'prefill' => $prefill,
                'draft' => (string) ($tpl['draft'] ?? $tpl['prompt'] ?? ''),
                'variables' => array_values(array_map('strval', $vars)),
                'sort_order' => (int) ($tpl['sort_order'] ?? 500),
                'is_featured' => (bool) ($tpl['is_featured'] ?? false),
            ],
        ];
    }

    /**
     * @param  list<mixed>  $datasets
     * @return list<array<string, mixed>>
     */
    private function namespaceDatasets(string $packKey, array $datasets): array
    {
        $out = [];
        foreach ($datasets as $ds) {
            if (! is_array($ds)) {
                continue;
            }
            $dk = (string) ($ds['key'] ?? '');
            if ($dk === '') {
                continue;
            }
            $out[] = array_merge($ds, [
                'key' => 'pack:'.$packKey.':'.$dk,
                'pack_key' => $packKey,
                'dataset_key' => $dk,
            ]);
        }

        return $out;
    }
}
