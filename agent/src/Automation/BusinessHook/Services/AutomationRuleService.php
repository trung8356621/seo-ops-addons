<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationRunMode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationConditionEngine;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class AutomationRuleService
{
    public function __construct(
        private readonly BusinessEventRegistry $eventRegistry,
        private readonly AutomationActionRegistry $actionRegistry,
        private readonly AutomationConditionEngine $conditionEngine,
        private readonly AutomationRuleMatcher $matcher,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $actions
     */
    public function createRule(array $data, array $actions = [], ?int $actorId = null): AutomationRule
    {
        $this->validateRulePayload($data, $actions);

        return \App\Support\Automation\AutomationConnection::db()->transaction(function () use ($data, $actions, $actorId): AutomationRule {
            $rule = AutomationRule::query()->create([
                'code' => (string) $data['code'],
                'name' => (string) $data['name'],
                'description' => $data['description'] ?? null,
                'classification' => (string) ($data['classification'] ?? 'business'),
                'visibility' => (string) ($data['visibility'] ?? 'user'),
                'event_name' => (string) $data['event_name'],
                'is_enabled' => (bool) ($data['is_enabled'] ?? false),
                'priority' => (int) ($data['priority'] ?? 100),
                'stop_on_failure' => (bool) ($data['stop_on_failure'] ?? true),
                'run_mode' => (string) ($data['run_mode'] ?? AutomationRunMode::Queued->value),
                'workflow_mode' => (string) ($data['workflow_mode'] ?? 'linear'),
                'trigger_type' => (string) ($data['trigger_type'] ?? 'event'),
                'schedule_expression' => $data['schedule_expression'] ?? null,
                'schedule_timezone' => $data['schedule_timezone'] ?? null,
                'next_run_at' => $data['next_run_at'] ?? null,
                'version' => 1,
                'conditions' => $data['conditions'] ?? null,
                'settings' => $data['settings'] ?? null,
                'locale_settings' => $data['locale_settings'] ?? null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $this->syncActions($rule, $actions);

            return $rule->fresh('actions') ?? $rule;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>|null  $actions
     */
    public function updateRule(AutomationRule $rule, array $data, ?array $actions = null, ?int $actorId = null): AutomationRule
    {
        if ($actions !== null) {
            $this->validateRulePayload(array_merge([
                'code' => $rule->code,
                'name' => $rule->name,
                'event_name' => $data['event_name'] ?? $rule->event_name,
            ], $data), $actions);
        } elseif (isset($data['event_name']) || isset($data['conditions'])) {
            $this->validateRulePayload(array_merge([
                'code' => $rule->code,
                'name' => $rule->name,
                'event_name' => $data['event_name'] ?? $rule->event_name,
                'conditions' => $data['conditions'] ?? $rule->conditions,
            ], $data), $rule->actions->map(fn (AutomationRuleAction $a): array => [
                'action_code' => $a->action_code,
                'position' => $a->position,
            ])->all());
        }

        return \App\Support\Automation\AutomationConnection::db()->transaction(function () use ($rule, $data, $actions, $actorId): AutomationRule {
            $bumpVersion = $this->shouldBumpVersion($rule, $data, $actions);

            $fill = [
                'name' => $data['name'] ?? $rule->name,
                'description' => array_key_exists('description', $data) ? $data['description'] : $rule->description,
                'event_name' => $data['event_name'] ?? $rule->event_name,
                'priority' => isset($data['priority']) ? (int) $data['priority'] : $rule->priority,
                'stop_on_failure' => isset($data['stop_on_failure']) ? (bool) $data['stop_on_failure'] : $rule->stop_on_failure,
                'run_mode' => $data['run_mode'] ?? $rule->run_mode,
                'workflow_mode' => $data['workflow_mode'] ?? $rule->workflow_mode ?? 'linear',
                'trigger_type' => $data['trigger_type'] ?? $rule->trigger_type ?? 'event',
                'schedule_expression' => array_key_exists('schedule_expression', $data) ? $data['schedule_expression'] : $rule->schedule_expression,
                'schedule_timezone' => array_key_exists('schedule_timezone', $data) ? $data['schedule_timezone'] : $rule->schedule_timezone,
                'conditions' => array_key_exists('conditions', $data) ? $data['conditions'] : $rule->conditions,
                'settings' => array_key_exists('settings', $data) ? $data['settings'] : $rule->settings,
                'locale_settings' => array_key_exists('locale_settings', $data) ? $data['locale_settings'] : $rule->locale_settings,
                'updated_by' => $actorId,
            ];

            if ($bumpVersion) {
                $fill['version'] = (int) $rule->version + 1;
            }

            if (isset($data['is_enabled'])) {
                $fill['is_enabled'] = (bool) $data['is_enabled'];
            }

            $rule->fill($fill);
            $rule->save();

            if ($actions !== null) {
                AutomationRuleAction::query()->where('automation_rule_id', $rule->id)->delete();
                $this->syncActions($rule, $actions);
            }

            return $rule->fresh('actions') ?? $rule;
        });
    }

    public function disable(AutomationRule $rule, ?int $actorId = null): AutomationRule
    {
        $rule->forceFill([
            'is_enabled' => false,
            'updated_by' => $actorId,
        ])->save();

        // Pending/processing executions already queued must not create side effects.
        AutomationExecution::query()
            ->where('automation_rule_id', $rule->id)
            ->whereIn('status', [
                AutomationExecutionStatus::Pending->value,
                AutomationExecutionStatus::Processing->value,
            ])
            ->update([
                'cancellation_requested_at' => now(),
            ]);

        return $rule->fresh('actions') ?? $rule;
    }

    /**
     * @return list<array{rule_id: int, rule_code: string}>
     */
    public function findConflictingWordpressRules(AutomationRule $rule): array
    {
        $wpAction = AutomationActionCode::WordpressArticleSync->value;

        $candidates = AutomationRule::query()
            ->where('event_name', $rule->event_name)
            ->where('is_enabled', true)
            ->where('id', '!=', $rule->id)
            ->where(function ($query) use ($rule): void {
                $query->whereNull('site_id');
                if ($rule->site_id !== null) {
                    $query->orWhere('site_id', $rule->site_id);
                }
            })
            ->with(['actions', 'nodes'])
            ->get();

        $conflicts = [];
        foreach ($candidates as $candidate) {
            $hasLinear = $candidate->actions->contains(
                static fn ($a): bool => (string) $a->action_code === $wpAction && (bool) $a->is_enabled,
            );
            $hasGraph = $candidate->nodes->contains(
                static fn ($n): bool => (string) ($n->action_code ?? '') === $wpAction && (bool) $n->is_enabled,
            );
            if ($hasLinear || $hasGraph) {
                $conflicts[] = [
                    'rule_id' => (int) $candidate->id,
                    'rule_code' => (string) $candidate->code,
                ];
            }
        }

        return $conflicts;
    }

    public function enable(AutomationRule $rule, ?int $actorId = null): AutomationRule
    {
        $conflicts = $this->findConflictingWordpressRules($rule);
        $ruleHasWp = $rule->actions()->where('action_code', AutomationActionCode::WordpressArticleSync->value)->exists()
            || $rule->nodes()->where('action_code', AutomationActionCode::WordpressArticleSync->value)->exists();

        if ($ruleHasWp && $conflicts !== []) {
            Log::warning('automation.wordpress.rule_conflict_on_enable', [
                'rule_id' => $rule->id,
                'rule_code' => $rule->code,
                'conflicts' => $conflicts,
            ]);
        }

        // Runtime toggle only — không tăng version.
        $rule->forceFill([
            'is_enabled' => true,
            'updated_by' => $actorId,
        ])->save();

        return $rule->fresh('actions') ?? $rule;
    }

    public function duplicate(AutomationRule $rule, ?int $actorId = null): AutomationRule
    {
        $rule->loadMissing('actions');
        $code = $rule->code.'-copy-'.Str::lower(Str::random(4));

        return $this->createRule([
            'code' => $code,
            'name' => $rule->name.' (copy)',
            'description' => $rule->description,
            'event_name' => $rule->event_name,
            'is_enabled' => false,
            'priority' => $rule->priority,
            'stop_on_failure' => $rule->stop_on_failure,
            'run_mode' => $rule->run_mode,
            'conditions' => $rule->conditions,
            'settings' => $rule->settings,
            'locale_settings' => $rule->locale_settings,
        ], $rule->actions->map(static fn (AutomationRuleAction $action): array => [
            'action_code' => $action->action_code,
            'position' => $action->position,
            'is_enabled' => $action->is_enabled,
            'continue_on_failure' => $action->continue_on_failure,
            'delay_seconds' => $action->delay_seconds,
            'input_mapping' => $action->input_mapping,
            'settings' => $action->settings,
        ])->all(), $actorId);
    }

    /**
     * @return array{matched: bool, errors: list<string>}
     */
    public function validate(AutomationRule $rule): array
    {
        $errors = [];
        if (! $this->eventRegistry->has($rule->event_name)) {
            $errors[] = "Event [{$rule->event_name}] not registered.";
        }
        $errors = array_merge($errors, $this->conditionEngine->validate($rule->conditions));
        foreach ($rule->actions as $action) {
            if (! $this->actionRegistry->has($action->action_code)) {
                $errors[] = "Action [{$action->action_code}] not registered.";
            }
        }

        return ['matched' => $errors === [], 'errors' => $errors];
    }

    /**
     * @return array{matched: bool, would_create_execution: bool}
     */
    public function testAgainstEvent(AutomationRule $rule, BusinessEvent $event): array
    {
        if ($rule->event_name !== $event->event_name) {
            return ['matched' => false, 'would_create_execution' => false];
        }

        $sources = $this->matcher->buildSources($event);
        $matched = $this->conditionEngine->matches($rule->conditions, $sources);

        return [
            'matched' => $matched,
            'would_create_execution' => $matched && $rule->is_enabled,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $actions
     */
    private function validateRulePayload(array $data, array $actions): void
    {
        $errors = [];
        $eventName = (string) ($data['event_name'] ?? '');
        if ($eventName === '' || ! $this->eventRegistry->has($eventName)) {
            $errors[] = 'event_name must be a registered business event.';
        }

        $errors = array_merge($errors, $this->conditionEngine->validate(
            is_array($data['conditions'] ?? null) ? $data['conditions'] : null,
        ));

        $isGraph = ($data['workflow_mode'] ?? 'linear') === 'graph';
        if ($isGraph) {
            if ($errors !== []) {
                throw new AutomationException(
                    BusinessHookErrorCode::RuleValidationFailed->value,
                    implode(' ', $errors),
                );
            }

            return;
        }

        $positions = [];
        foreach ($actions as $action) {
            $code = (string) ($action['action_code'] ?? '');
            if ($code === '' || ! $this->actionRegistry->has($code)) {
                $errors[] = "Unknown action_code [{$code}].";
            }
            $position = (int) ($action['position'] ?? -1);
            if ($position < 0) {
                $errors[] = 'Action position must be >= 0.';
            }
            if (isset($positions[$position])) {
                $errors[] = "Duplicate action position [{$position}].";
            }
            $positions[$position] = true;
        }

        $runMode = (string) ($data['run_mode'] ?? AutomationRunMode::Queued->value);
        if (! in_array($runMode, [AutomationRunMode::Queued->value, AutomationRunMode::Sync->value], true)) {
            $errors[] = 'Invalid run_mode.';
        }

        if ($errors !== []) {
            throw new AutomationException(
                BusinessHookErrorCode::RuleValidationFailed->value,
                implode(' ', $errors),
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function syncActions(AutomationRule $rule, array $actions): void
    {
        foreach ($actions as $action) {
            AutomationRuleAction::query()->create([
                'automation_rule_id' => $rule->id,
                'action_code' => (string) $action['action_code'],
                'position' => (int) $action['position'],
                'is_enabled' => (bool) ($action['is_enabled'] ?? true),
                'continue_on_failure' => (bool) ($action['continue_on_failure'] ?? false),
                'delay_seconds' => (int) ($action['delay_seconds'] ?? 0),
                'input_mapping' => $action['input_mapping'] ?? null,
                'settings' => $action['settings'] ?? null,
            ]);
        }
    }

    /**
     * Tăng version khi cấu hình rule/actions đổi.
     * Không tăng khi chỉ toggle is_enabled, name, description, locale_settings cosmetic, updated_by.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>|null  $actions
     */
    private function shouldBumpVersion(AutomationRule $rule, array $data, ?array $actions): bool
    {
        if ($actions !== null) {
            return true;
        }

        $configKeys = [
            'event_name',
            'conditions',
            'priority',
            'run_mode',
            'stop_on_failure',
            'settings',
        ];

        foreach ($configKeys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $incoming = $data[$key];
            $current = $rule->{$key};

            if ($key === 'priority' || $key === 'stop_on_failure') {
                if ((string) json_encode($incoming) !== (string) json_encode($current)) {
                    return true;
                }

                continue;
            }

            if ((string) json_encode($incoming) !== (string) json_encode($current)) {
                return true;
            }
        }

        return false;
    }
}
