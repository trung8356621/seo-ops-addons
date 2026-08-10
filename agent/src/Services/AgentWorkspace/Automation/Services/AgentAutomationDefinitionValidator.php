<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationConditionEvaluator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationScheduleResolver;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationDefinitionData;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationDefinitionRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;

/**
 * Validates + normalizes automation definitions. Untrusted input.
 */
final class AgentAutomationDefinitionValidator
{
    /** @var list<string> */
    public const STEP_TYPES = [
        'read_skill',
        'planning',
        'execution_preview',
        'condition',
        'notification',
    ];

    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AgentAutomationScheduleResolver $schedules,
        private readonly AgentAutomationConditionEvaluator $conditions,
        private readonly int $maxSteps = 5,
        private readonly int $maxDefinitionBytes = 65536,
    ) {}

    /**
     * @return array{ok: bool, definition?: AgentAutomationDefinitionData, errors: list<string>, warnings: list<string>}
     */
    public function validate(AgentWorkspaceContext $context, AgentAutomationDefinitionRequest $request): array
    {
        $errors = [];
        $warnings = [];

        $sizeProbe = [
            'name' => $request->name,
            'type' => $request->type,
            'trigger' => $request->trigger,
            'workflow' => $request->workflow,
            'condition' => $request->condition,
            'notification' => $request->notification,
            'policy' => $request->policy,
        ];
        $encoded = json_encode($sizeProbe, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > $this->maxDefinitionBytes) {
            return ['ok' => false, 'errors' => ['definition_too_large'], 'warnings' => []];
        }

        if ($request->name === '' || mb_strlen($request->name) > 255) {
            $errors[] = 'invalid_name';
        }

        if (! in_array($request->type, AgentAutomationDefinitionData::ALLOWED_TYPES, true)) {
            $errors[] = 'unsupported_type';
        }

        if (! in_array($request->scopeType, ['site', 'project', 'workspace'], true)) {
            $errors[] = 'invalid_scope_type';
        }

        // Browser must not override owner/site — ignore any injected fields.
        if (isset($request->policy['owner_user_id']) || isset($request->policy['site_id'])) {
            $errors[] = 'browser_owner_site_override_rejected';
        }

        $trigger = $request->trigger;
        $timezone = $request->timezone ?? (string) ($trigger['timezone'] ?? 'UTC');
        $trigger['timezone'] = $timezone;

        $schedule = $this->schedules->resolve($trigger);
        if (! ($schedule['ok'] ?? false)) {
            foreach (($schedule['errors'] ?? ['invalid_schedule']) as $e) {
                $errors[] = (string) $e;
            }
        }

        $workflow = $request->workflow;
        if (count($workflow) === 0) {
            $errors[] = 'workflow_required';
        }
        if (count($workflow) > $this->maxSteps) {
            $errors[] = 'too_many_steps';
        }

        $capabilities = [];
        $allowedPaths = ['summary', 'result', 'data', 'status', 'count', 'items'];
        $normalizedSteps = [];

        foreach ($workflow as $idx => $step) {
            if (! is_array($step)) {
                $errors[] = 'invalid_step';
                continue;
            }
            $stepType = (string) ($step['type'] ?? '');
            if (! in_array($stepType, self::STEP_TYPES, true)) {
                $errors[] = 'unsupported_step_type';
                continue;
            }
            if (isset($step['depends_on']) || isset($step['parallel']) || isset($step['children'])) {
                $errors[] = 'dag_or_nested_not_allowed';
            }
            if (isset($step['create_automation']) || isset($step['automation'])) {
                $errors[] = 'nested_automation_forbidden';
            }

            $norm = [
                'type' => $stepType,
                'index' => $idx,
            ];

            if ($stepType === 'read_skill' || $stepType === 'execution_preview') {
                $skillKey = trim((string) ($step['skill_key'] ?? ''));
                $skill = $this->skills->get($skillKey);
                if ($skill === null) {
                    $errors[] = 'invalid_skill';
                } elseif ($skill->isHidden) {
                    $errors[] = 'internal_skill_rejected';
                } else {
                    if ($stepType === 'read_skill' && $skill->confirmationPolicy !== 'none') {
                        $warnings[] = 'read_skill_has_confirmation_policy';
                    }
                    if ($stepType === 'execution_preview' && $skill->confirmationPolicy === 'none') {
                        $warnings[] = 'execution_preview_on_read_skill';
                    }
                    $norm['skill_key'] = $skill->key;
                    $norm['capability'] = $skill->capability;
                    $norm['confirmation_policy'] = $skill->confirmationPolicy;
                    $capabilities[] = $skill->capability;
                    $norm['input'] = is_array($step['input'] ?? null) ? $step['input'] : [];
                }
            }

            if ($stepType === 'planning') {
                $norm['prompt'] = mb_substr(trim((string) ($step['prompt'] ?? '')), 0, 2000);
                if ($norm['prompt'] === '') {
                    $errors[] = 'planning_prompt_required';
                }
            }

            if ($stepType === 'condition') {
                $cond = is_array($step['condition'] ?? null) ? $step['condition'] : ($request->condition ?? []);
                $condErrors = $this->conditions->validateSchema(is_array($cond) ? $cond : [], $allowedPaths);
                foreach ($condErrors as $ce) {
                    $errors[] = $ce;
                }
                $norm['condition'] = $cond;
            }

            if ($stepType === 'notification') {
                $norm['notification'] = is_array($step['notification'] ?? null) ? $step['notification'] : null;
            }

            $normalizedSteps[] = $norm;
        }

        // Type-specific defaults
        if ($request->type === AgentAutomationDefinitionData::TYPE_SCHEDULED_REPORT) {
            foreach ($normalizedSteps as $s) {
                if (($s['type'] ?? '') === 'execution_preview') {
                    $warnings[] = 'report_should_prefer_read_skill';
                }
            }
        }

        if ($request->type === AgentAutomationDefinitionData::TYPE_PLANNING_WORKFLOW) {
            $hasPlanning = false;
            foreach ($normalizedSteps as $s) {
                if (($s['type'] ?? '') === 'planning') {
                    $hasPlanning = true;
                }
                if (($s['type'] ?? '') === 'execution_preview') {
                    $errors[] = 'planning_workflow_cannot_execute';
                }
            }
            if (! $hasPlanning) {
                $errors[] = 'planning_step_required';
            }
        }

        if ($request->type === AgentAutomationDefinitionData::TYPE_GUARDED_ACTION) {
            $hasPreview = false;
            foreach ($normalizedSteps as $s) {
                if (($s['type'] ?? '') === 'execution_preview') {
                    $hasPreview = true;
                }
            }
            if (! $hasPreview) {
                $errors[] = 'guarded_action_requires_execution_preview';
            }
        }

        $condition = $request->condition;
        if ($condition !== null) {
            foreach ($this->conditions->validateSchema($condition, $allowedPaths) as $ce) {
                $errors[] = $ce;
            }
        }

        $notification = $this->normalizeNotification($request->notification, $errors);
        $policy = $this->normalizePolicy($request->policy, $errors, $warnings);

        // Cross-site refs fail closed
        if ($request->scopeRef !== null && $request->scopeType === 'site' && $request->scopeRef !== $context->siteRef) {
            $errors[] = 'cross_site_scope_rejected';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors)), 'warnings' => $warnings];
        }

        $normalizedTrigger = $schedule['normalized'] ?? $trigger;
        $normalizedTrigger['resolved_next_run_at'] = $schedule['next_run_at'] ?? null;
        $normalizedTrigger['preview_occurrences'] = $schedule['preview_occurrences'] ?? [];

        foreach (($schedule['warnings'] ?? []) as $w) {
            $warnings[] = (string) $w;
        }

        $hashPayload = [
            'type' => $request->type,
            'trigger' => $normalizedTrigger,
            'workflow' => $normalizedSteps,
            'condition' => $condition,
            'notification' => $notification,
            'policy' => $policy,
            'scope_type' => $request->scopeType,
            'scope_ref' => $request->scopeRef ?? $context->siteRef,
        ];
        $definitionHash = hash('sha256', json_encode($hashPayload, JSON_THROW_ON_ERROR));

        $definition = new AgentAutomationDefinitionData(
            name: $request->name,
            type: $request->type,
            trigger: $normalizedTrigger,
            workflow: $normalizedSteps,
            timezone: $timezone,
            definitionHash: $definitionHash,
            description: $request->description,
            scopeType: $request->scopeType,
            scopeRef: $request->scopeRef ?? $context->siteRef,
            condition: $condition,
            notification: $notification,
            policy: $policy,
            enabled: $request->enabled,
            conversationId: $request->conversationId,
            warnings: array_values(array_unique($warnings)),
        );

        return [
            'ok' => true,
            'definition' => $definition,
            'errors' => [],
            'warnings' => $definition->warnings,
            'capabilities' => array_values(array_unique($capabilities)),
            'schedule' => $schedule,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private function normalizeNotification(?array $raw, array &$errors): ?array
    {
        if ($raw === null) {
            return [
                'policy' => 'condition_matched',
                'destinations' => ['agent_workspace'],
                'cooldown_minutes' => 60,
            ];
        }
        $policy = (string) ($raw['policy'] ?? 'condition_matched');
        $allowed = ['always', 'condition_matched', 'change_only', 'failure_only', 'digest', 'silent_success'];
        if (! in_array($policy, $allowed, true)) {
            $errors[] = 'invalid_notification_policy';
        }
        $destinations = is_array($raw['destinations'] ?? null) ? array_values($raw['destinations']) : ['agent_workspace'];
        $allowedDest = ['agent_workspace', 'database_notification', 'email'];
        foreach ($destinations as $d) {
            if (! in_array((string) $d, $allowedDest, true)) {
                $errors[] = 'invalid_notification_destination';
            }
        }
        if ($destinations === []) {
            $errors[] = 'notification_destination_required';
        }

        return [
            'policy' => $policy,
            'destinations' => $destinations,
            'cooldown_minutes' => max(0, (int) ($raw['cooldown_minutes'] ?? 60)),
            'severity' => (string) ($raw['severity'] ?? 'info'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function normalizePolicy(?array $raw, array &$errors, array &$warnings): array
    {
        $raw ??= [];
        if (($raw['auto_confirm'] ?? false) === true || ($raw['disable_confirmation'] ?? false) === true) {
            $errors[] = 'auto_confirm_rejected';
        }
        if (isset($raw['confirmation_policy']) && $raw['confirmation_policy'] === 'none' && ($raw['force_none'] ?? false)) {
            $errors[] = 'confirmation_policy_cannot_be_disabled';
        }

        $autoSafe = (bool) ($raw['auto_execute_safe_writes'] ?? false);
        if ($autoSafe) {
            // Default false; permitting true only when capability metadata later says automation_safe — still no confirm override.
            $warnings[] = 'auto_execute_safe_writes_ignored_default_false';
            $autoSafe = false;
        }

        return [
            'auto_execute_safe_writes' => false,
            'catch_up' => in_array(($raw['catch_up'] ?? 'skip_missed'), ['skip_missed', 'run_once'], true)
                ? (string) ($raw['catch_up'] ?? 'skip_missed')
                : 'skip_missed',
            'require_confirmation' => true,
            'max_attempts' => max(1, min(5, (int) ($raw['max_attempts'] ?? 3))),
            'permission_lost_action' => in_array(($raw['permission_lost_action'] ?? 'pause'), ['pause', 'mark_invalid'], true)
                ? (string) ($raw['permission_lost_action'] ?? 'pause')
                : 'pause',
            'no_overlap' => (bool) ($raw['no_overlap'] ?? true),
        ];
    }
}
