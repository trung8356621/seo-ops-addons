<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningResponse;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentProposedIntent;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentProposedPlan;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentProposedPlanStep;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Security\AgentPlanningOutputSanitizer;

final class DeterministicAgentPlanRepairer
{
    /** @var array<string, string> */
    private const INPUT_ALIASES = [
        'name' => 'project_name',
        'project' => 'project_ref',
        'workspace' => 'workspace_ref',
        'article' => 'article_ref',
    ];

    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AgentPlanningOutputSanitizer $outputSanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     * @return array{response: AgentPlanningResponse, repair_actions: list<string>}
     */
    public function repair(array $raw): array
    {
        $actions = [];
        $sanitized = $this->outputSanitizer->sanitize($raw);
        $payload = $sanitized['payload'];
        foreach ($sanitized['stripped'] as $field) {
            $actions[] = 'stripped:'.$field;
        }

        if (isset($payload['confidence']) && is_string($payload['confidence']) && is_numeric($payload['confidence'])) {
            $payload['confidence'] = (float) $payload['confidence'];
            $actions[] = 'normalize_confidence';
        }

        if (isset($payload['intent']) && is_array($payload['intent'])) {
            $payload['intent'] = $this->repairIntent($payload['intent'], $actions);
        }
        if (isset($payload['plan']) && is_array($payload['plan'])) {
            $payload['plan'] = $this->repairPlan($payload['plan'], $actions);
        }

        foreach (['assumptions', 'warnings', 'suggested_skills', 'clarifying_questions'] as $listKey) {
            if (! isset($payload[$listKey])) {
                $payload[$listKey] = [];
                $actions[] = 'default_empty:'.$listKey;
            }
        }

        return [
            'response' => AgentPlanningResponse::fromArray($payload),
            'repair_actions' => array_values(array_unique($actions)),
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @param  list<string>  $actions
     * @return array<string, mixed>
     */
    private function repairIntent(array $intent, array &$actions): array
    {
        $skillKey = (string) ($intent['skill_key'] ?? '');
        if ($skillKey === '' && isset($intent['command']) && is_string($intent['command'])) {
            $skillKey = (string) $intent['command'];
        }
        $resolved = $this->resolveSkillKey($skillKey);
        if ($resolved !== null && $resolved !== $skillKey) {
            $actions[] = 'slash_to_skill:'.$skillKey.'->'.$resolved;
            $intent['skill_key'] = $resolved;
        } elseif ($resolved !== null) {
            $intent['skill_key'] = $resolved;
        }

        if (isset($intent['input']) && is_array($intent['input'])) {
            $intent['input'] = $this->aliasInputs($intent['input'], $actions);
        }

        return $intent;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<string>  $actions
     * @return array<string, mixed>
     */
    private function repairPlan(array $plan, array &$actions): array
    {
        $steps = is_array($plan['steps'] ?? null) ? $plan['steps'] : [];
        $repaired = [];
        $i = 1;
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            if (! isset($step['index']) || ! is_numeric($step['index'])) {
                $step['index'] = $i;
                $actions[] = 'fill_step_index:'.$i;
            }
            $skillKey = (string) ($step['skill_key'] ?? '');
            if ($skillKey === '' && isset($step['command']) && is_string($step['command'])) {
                $skillKey = (string) $step['command'];
            }
            $resolved = $this->resolveSkillKey($skillKey);
            if ($resolved !== null && $resolved !== $skillKey) {
                $actions[] = 'slash_to_skill:'.$skillKey.'->'.$resolved;
                $step['skill_key'] = $resolved;
            } elseif ($resolved !== null) {
                $step['skill_key'] = $resolved;
            }
            if (isset($step['input']) && is_array($step['input'])) {
                $step['input'] = $this->aliasInputs($step['input'], $actions);
            }
            if (! isset($step['depends_on']) || ! is_array($step['depends_on'])) {
                $step['depends_on'] = $i > 1 ? [$i - 1] : [];
                $actions[] = 'default_depends_on:'.$i;
            }
            $repaired[] = $step;
            $i++;
        }
        $plan['steps'] = $repaired;

        return $plan;
    }

    private function resolveSkillKey(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $skill = $this->skills->get($raw);
        if ($skill !== null) {
            return $skill->key;
        }
        $skill = $this->skills->resolveSlashCommand($raw);
        if ($skill !== null) {
            return $skill->key;
        }
        if (! str_starts_with($raw, '/')) {
            $skill = $this->skills->resolveSlashCommand('/'.$raw);

            return $skill?->key;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $actions
     * @return array<string, mixed>
     */
    private function aliasInputs(array $input, array &$actions): array
    {
        $out = $input;
        foreach (self::INPUT_ALIASES as $from => $to) {
            if (array_key_exists($from, $out) && ! array_key_exists($to, $out)) {
                $out[$to] = $out[$from];
                unset($out[$from]);
                $actions[] = 'input_alias:'.$from.'->'.$to;
            }
        }

        return $out;
    }
}
