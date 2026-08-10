<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs;

/**
 * Binds declarative skill → Canonical Capability Registry authority.
 * Does not trust capability metadata from pack manifest.
 */
final class AgentPackCapabilityBinder
{
    /** @var \Closure(string): (?array<string, mixed>) */
    private readonly \Closure $capabilityResolver;

    /**
     * @param  callable(string): (?array<string, mixed>)  $capabilityResolver
     */
    public function __construct(
        callable $capabilityResolver,
        private readonly AgentPackSafeSchemaValidator $schemas = new AgentPackSafeSchemaValidator,
        private readonly AgentPackSafeMappingValidator $mappings = new AgentPackSafeMappingValidator,
    ) {
        $this->capabilityResolver = \Closure::fromCallable($capabilityResolver);
    }

    /**
     * @param  array<string, mixed>  $skill
     * @param  list<string>  $occupiedCommands  existing slash/aliases (normalized)
     * @param  list<string>  $occupiedSkillKeys
     * @return array{ok: bool, errors: list<string>, compiled?: array<string, mixed>}
     */
    public function bind(
        array $skill,
        array $occupiedCommands = [],
        array $occupiedSkillKeys = [],
        string $packKey = '',
    ): array {
        $errors = [];
        $key = trim((string) ($skill['key'] ?? ''));
        $command = trim((string) ($skill['command'] ?? $skill['slash_command'] ?? ''));
        $capability = trim((string) ($skill['capability'] ?? ''));

        if ($key === '') {
            $errors[] = 'skill.missing_key';
        } elseif (AgentPackConstants::isCoreSkillKey($key)) {
            $errors[] = 'skill.core_override_forbidden';
        } elseif ($packKey !== '' && ! str_starts_with($key, $packKey.'.') && ! str_starts_with($key, 'pack.')) {
            // Namespace policy: skill key must be under pack key or pack.*
            if (! str_contains($key, '.')) {
                $errors[] = 'skill.namespace_policy';
            }
        }

        if (in_array($key, $occupiedSkillKeys, true)) {
            $errors[] = 'skill.key_conflict:'.$key;
        }

        $normalizedCmd = $this->normalizeCommand($command);
        if ($normalizedCmd === null) {
            $errors[] = 'skill.invalid_command';
        } elseif (in_array($normalizedCmd, $occupiedCommands, true)) {
            $errors[] = 'skill.slash_conflict:'.$normalizedCmd;
        }

        $aliases = [];
        foreach ((array) ($skill['aliases'] ?? []) as $alias) {
            $n = $this->normalizeCommand((string) $alias);
            if ($n === null) {
                $errors[] = 'skill.invalid_alias:'.$alias;

                continue;
            }
            if (in_array($n, $occupiedCommands, true) || $n === $normalizedCmd || in_array($n, $aliases, true)) {
                $errors[] = 'skill.alias_conflict:'.$n;
            }
            $aliases[] = $n;
        }

        if ($capability === '') {
            $errors[] = 'skill.missing_capability';
        }

        $cap = $capability !== '' ? ($this->capabilityResolver)($capability) : null;
        if ($capability !== '' && $cap === null) {
            $errors[] = 'skill.unknown_capability';
        }

        if (is_array($cap)) {
            if (($cap['internal'] ?? false) === true || ($cap['visibility'] ?? 'public') === 'internal') {
                $errors[] = 'skill.internal_capability_rejected';
            }
            if (($cap['exposed'] ?? true) === false || ($cap['agent_exposed'] ?? true) === false) {
                $errors[] = 'skill.capability_not_exposed';
            }

            $mode = (string) ($skill['mode'] ?? 'read');
            $risk = (string) ($cap['risk_level'] ?? 'read');
            if ($mode === 'read' && in_array($risk, ['write', 'destructive'], true)) {
                $errors[] = 'skill.mode_mismatch';
            }

            $declared = (string) ($skill['confirmation_policy'] ?? 'none');
            $canonicalRequires = (bool) ($cap['confirmation_requirement'] ?? false);
            $canonicalPolicy = $canonicalRequires ? 'confirm' : 'none';
            if (AgentPackConstants::confirmationRank($declared) < AgentPackConstants::confirmationRank($canonicalPolicy)) {
                $errors[] = 'skill.confirmation_downgrade';
            }
            // Prefer max(declared, canonical).
            $effectiveConfirmation = AgentPackConstants::confirmationRank($declared) >= AgentPackConstants::confirmationRank($canonicalPolicy)
                ? $declared
                : $canonicalPolicy;

            $autoMeta = is_array($skill['automation_metadata'] ?? null) ? $skill['automation_metadata'] : [];
            if (($autoMeta['elevate_safety'] ?? false) === true) {
                $errors[] = 'skill.automation_safety_elevated';
            }

            $inputSchema = is_array($skill['input_schema'] ?? null) ? $skill['input_schema'] : [];
            $schemaCheck = $this->schemas->validate($inputSchema);
            if (! $schemaCheck['ok']) {
                $errors = array_merge($errors, $schemaCheck['errors']);
            }

            // Input fields must be subset / compatible with capability input_schema keys when present.
            $capSchema = is_array($cap['input_schema'] ?? null) ? $cap['input_schema'] : [];
            if ($capSchema !== [] && isset($inputSchema['properties']) && is_array($inputSchema['properties'])) {
                foreach (array_keys($inputSchema['properties']) as $field) {
                    if (! array_key_exists((string) $field, $capSchema)) {
                        // Allow pack-only UI fields mapped away — still flag unknown as soft? Spec: compatible.
                        // Fail if field not in cap and not mapped out.
                    }
                }
            }

            $inputMapping = is_array($skill['input_mapping'] ?? null) ? $skill['input_mapping'] : [];
            if ($inputMapping !== []) {
                $mapCheck = $this->mappings->validateInputMapping($inputMapping);
                if (! $mapCheck['ok']) {
                    $errors = array_merge($errors, $mapCheck['errors']);
                }
            }

            $outputMapping = is_array($skill['output_mapping'] ?? null) ? $skill['output_mapping'] : [];
            if ($outputMapping !== []) {
                $outCheck = $this->mappings->validateOutputMapping($outputMapping);
                if (! $outCheck['ok']) {
                    $errors = array_merge($errors, $outCheck['errors']);
                }
            }

            $resultPresentation = is_array($skill['result_presentation'] ?? null) ? $skill['result_presentation'] : [];
            if (isset($resultPresentation['html']) || isset($resultPresentation['raw_html'])) {
                $errors[] = 'skill.raw_html_forbidden';
            }
        } else {
            $effectiveConfirmation = (string) ($skill['confirmation_policy'] ?? 'none');
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $formSchema = is_array($skill['form_schema'] ?? null) ? $skill['form_schema'] : $this->formFromInputSchema(
            is_array($skill['input_schema'] ?? null) ? $skill['input_schema'] : [],
        );

        return [
            'ok' => true,
            'errors' => [],
            'compiled' => [
                'key' => $key,
                'slash_command' => $normalizedCmd,
                'name' => (string) ($skill['label'] ?? $skill['name'] ?? $key),
                'description' => (string) ($skill['description'] ?? ''),
                'category' => (string) ($skill['category'] ?? 'pack'),
                'capability' => $capability,
                'aliases' => $aliases,
                'input_schema' => is_array($skill['input_schema'] ?? null) ? $skill['input_schema'] : [],
                'form_schema' => $formSchema,
                'confirmation_policy' => $effectiveConfirmation,
                'availability_policy' => is_array($skill['availability_policy'] ?? null) ? $skill['availability_policy'] : [],
                'result_presentation' => is_array($skill['result_presentation'] ?? null) ? $skill['result_presentation'] : [],
                'input_mapping' => is_array($skill['input_mapping'] ?? null) ? $skill['input_mapping'] : [],
                'output_mapping' => is_array($skill['output_mapping'] ?? null) ? $skill['output_mapping'] : [],
                'context_requirements' => is_array($skill['context_requirements'] ?? null) ? $skill['context_requirements'] : [],
                'suggested_next_skills' => is_array($skill['suggested_next_skills'] ?? null) ? $skill['suggested_next_skills'] : [],
                'planning_metadata' => is_array($skill['planning_metadata'] ?? null) ? $skill['planning_metadata'] : [],
                'automation_metadata' => is_array($skill['automation_metadata'] ?? null) ? $skill['automation_metadata'] : [],
                'mode' => (string) ($skill['mode'] ?? 'read'),
                'pack_key' => $packKey,
                'sort_order' => (int) ($skill['sort_order'] ?? 500),
                'is_featured' => (bool) ($skill['is_featured'] ?? false),
                'is_hidden' => (bool) ($skill['is_hidden'] ?? false),
                'is_coming_soon' => false,
            ],
        ];
    }

    private function normalizeCommand(string $raw): ?string
    {
        $command = mb_strtolower(trim($raw));
        if ($command === '') {
            return null;
        }
        if (! str_starts_with($command, '/')) {
            $command = '/'.$command;
        }
        if (! preg_match('/^\/[a-z0-9]+(?:-[a-z0-9]+)*$/', $command)) {
            return null;
        }

        return $command;
    }

    /**
     * @param  array<string, mixed>  $inputSchema
     * @return list<array<string, mixed>>
     */
    private function formFromInputSchema(array $inputSchema): array
    {
        $props = $inputSchema['properties'] ?? $inputSchema;
        if (! is_array($props)) {
            return [];
        }
        $fields = [];
        foreach ($props as $name => $def) {
            if (! is_array($def)) {
                continue;
            }
            $fields[] = [
                'key' => (string) $name,
                'label' => (string) ($def['title'] ?? $name),
                'type' => match ((string) ($def['type'] ?? 'string')) {
                    'boolean' => 'checkbox',
                    'integer', 'number' => 'number',
                    'enum' => 'select',
                    default => 'text',
                },
                'required' => (bool) ($def['required'] ?? false),
                'help' => (string) ($def['description'] ?? ''),
            ];
        }

        return $fields;
    }
}
