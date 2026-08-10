<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentAutomation;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentAutomationRun;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentConversation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentConversationService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationConditionEvaluator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationNotificationService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationRepository;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationRunner;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationScheduleResolver;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationNotificationData;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationRunResult;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentExecutionOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentPlanningOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningRequest;
use App\Models\User;
use App\Support\RuntimeLogger;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use Throwable;

/**
 * Executes one automation run. Business actions only via Phase 2/3 orchestrators.
 */
final class DefaultAgentAutomationRunner implements AgentAutomationRunner
{
    /** @var list<string> */
    private const RETRYABLE = [
        'provider_error',
        'queue_error',
        'rate_limited',
        'transient_internal_error',
    ];

    public function __construct(
        private readonly AgentAutomationRepository $repository,
        private readonly AgentAutomationRunStateMachine $states,
        private readonly AgentAutomationLockService $locks,
        private readonly AgentAutomationQuotaService $quotas,
        private readonly AgentAutomationScheduleResolver $schedules,
        private readonly AgentAutomationConditionEvaluator $conditions,
        private readonly AgentAutomationNotificationService $notifications,
        private readonly AgentAutomationApprovalTokenService $approvalTokens,
        private readonly AgentExecutionOrchestrator $execution,
        private readonly AgentPlanningOrchestrator $planning,
        private readonly AgentConversationService $conversations,
        private readonly AgentSkillRegistry $skills,
    ) {}

    public function run(int $runId): AgentAutomationRunResult
    {
        $run = $this->repository->findRunById($runId);
        if ($run === null) {
            return new AgentAutomationRunResult(ok: false, status: 'failed', runHashId: '', error: ['code' => 'run_not_found']);
        }

        $automation = SeoAgentAutomation::query()->find($run->automation_id);
        if ($automation === null) {
            return new AgentAutomationRunResult(ok: false, status: 'failed', runHashId: (string) $run->hash_id, error: ['code' => 'automation_missing']);
        }

        $ownerToken = 'run:'.$run->hash_id;
        $lock = $this->locks->acquire((int) $automation->id, $ownerToken);
        if ($lock === null) {
            return $this->skip($run, $automation, 'overlap');
        }

        try {
            return $this->runLocked($run, $automation);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
            }
        }
    }

    private function runLocked(SeoAgentAutomationRun $run, SeoAgentAutomation $automation): AgentAutomationRunResult
    {
        if ($this->states->isTerminalStrict((string) $run->status)) {
            return new AgentAutomationRunResult(
                ok: true,
                status: (string) $run->status,
                runHashId: (string) $run->hash_id,
                occurrenceKey: (string) $run->occurrence_key,
                summary: $run->result_summary,
            );
        }

        if (! $automation->enabled) {
            return $this->skip($run, $automation, 'disabled');
        }
        if ((string) $automation->status === 'paused') {
            return $this->skip($run, $automation, 'paused');
        }

        if ((string) $automation->definition_hash !== (string) $run->definition_hash) {
            return $this->skip($run, $automation, 'stale_definition');
        }

        if ($this->repository->countConcurrentRunning((int) $automation->site_id) > $this->quotas->maxConcurrentRuns()) {
            return $this->skip($run, $automation, 'quota_exceeded');
        }

        $hourAgo = new DateTimeImmutable('-1 hour', new DateTimeZone('UTC'));
        if ($this->repository->countRunsSince((int) $automation->id, $hourAgo) > $this->quotas->maxRunsPerHour()) {
            return $this->skip($run, $automation, 'quota_exceeded');
        }

        $context = $this->rebuildContext($automation);
        if ($context === null) {
            return $this->permissionLost($run, $automation);
        }

        $this->transition($run, 'running', [
            'started_at' => now(),
        ]);

        $stepResults = [];
        $lastOutput = [];
        $conditionResult = null;
        $executionRef = null;
        $planningRequestId = null;
        $approvalHashId = null;
        $waitingApproval = false;

        $workflow = is_array($automation->workflow_json) ? $automation->workflow_json : [];
        $conversation = $this->resolveConversation($context, $automation);

        try {
            foreach ($workflow as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $type = (string) ($step['type'] ?? '');
                $result = match ($type) {
                    'read_skill' => $this->stepReadSkill($context, $conversation, $step),
                    'planning' => $this->stepPlanning($context, $conversation, $step),
                    'execution_preview' => $this->stepExecutionPreview($context, $conversation, $automation, $run, $step),
                    'condition' => $this->stepCondition($automation, $step, $lastOutput),
                    'notification' => ['ok' => true, 'type' => 'notification', 'deferred' => true],
                    default => ['ok' => false, 'error' => 'unsupported_step'],
                };

                $stepResults[] = array_merge(['type' => $type], $result);

                if (($result['ok'] ?? false) !== true) {
                    $category = (string) ($result['error_category'] ?? 'business_rule_violation');

                    return $this->fail($run, $automation, $stepResults, $category, $result);
                }

                if (isset($result['output']) && is_array($result['output'])) {
                    $lastOutput = $result['output'];
                }
                if (isset($result['condition_result'])) {
                    $conditionResult = $result['condition_result'];
                }
                if (isset($result['execution_ref'])) {
                    $executionRef = (string) $result['execution_ref'];
                }
                if (isset($result['planning_request_id'])) {
                    $planningRequestId = (string) $result['planning_request_id'];
                }
                if (($result['waiting_for_approval'] ?? false) === true) {
                    $waitingApproval = true;
                    $approvalHashId = isset($result['approval_hash_id']) ? (string) $result['approval_hash_id'] : null;
                    break;
                }
            }
        } catch (Throwable $e) {
            RuntimeLogger::report($e, ['automation_run' => $run->hash_id]);

            return $this->fail($run, $automation, $stepResults, 'transient_internal_error', [
                'message' => 'internal_error',
            ]);
        }

        if ($waitingApproval) {
            $this->transition($run, 'waiting_for_approval', [
                'step_results' => $stepResults,
                'execution_ref' => $executionRef,
                'result_summary' => ['waiting_for_approval' => true],
            ]);

            return new AgentAutomationRunResult(
                ok: true,
                status: 'waiting_for_approval',
                runHashId: (string) $run->hash_id,
                occurrenceKey: (string) $run->occurrence_key,
                stepResults: $stepResults,
                approvalHashId: $approvalHashId,
                executionRef: $executionRef,
            );
        }

        $matched = is_array($conditionResult) ? (bool) ($conditionResult['matched'] ?? true) : true;
        $changed = is_array($conditionResult) ? (bool) ($conditionResult['changed'] ?? true) : true;

        $status = 'succeeded';
        if (is_array($conditionResult) && ! $matched) {
            $status = 'no_change';
        } elseif (is_array($conditionResult) && $matched && ! $changed && ($automation->type === 'condition_watch')) {
            $status = 'no_change';
        }

        $notifyConfig = is_array($automation->notification_json) ? $automation->notification_json : [];
        $fingerprint = hash('sha256', json_encode([
            'automation' => $automation->hash_id,
            'status' => $status,
            'condition' => $conditionResult,
            'summary' => $lastOutput['summary'] ?? null,
        ], JSON_THROW_ON_ERROR));

        $notifData = new AgentAutomationNotificationData(
            policy: (string) ($notifyConfig['policy'] ?? 'condition_matched'),
            destinations: is_array($notifyConfig['destinations'] ?? null) ? $notifyConfig['destinations'] : ['agent_workspace'],
            title: 'Automation: '.$automation->name,
            body: 'Run '.$run->hash_id.' status '.$status,
            severity: $status === 'failed' ? 'error' : 'info',
            fingerprint: $fingerprint,
            payload: ['status' => $status, 'summary' => $lastOutput],
            runHashId: (string) $run->hash_id,
            automationHashId: (string) $automation->hash_id,
        );

        $quiet = is_array($automation->trigger_json['quiet_hours'] ?? null) ? $automation->trigger_json['quiet_hours'] : null;
        $notifyResult = $this->notifications->maybeNotify($context, $notifyConfig, $notifData, [
            'status' => $status,
            'condition_matched' => $matched,
            'changed' => $changed,
            'quiet_hours' => $quiet,
            'timezone' => (string) $automation->timezone,
            'max_notifications_per_hour' => $this->quotas->maxNotificationsPerHour(),
            'email_configured' => false,
        ]);

        $startedMs = $run->started_at !== null
            ? ((int) $run->started_at->getTimestamp() * 1000)
            : (int) (microtime(true) * 1000);
        $duration = max(0, (int) (microtime(true) * 1000) - $startedMs);

        $this->transition($run, $status, [
            'finished_at' => now(),
            'duration_ms' => $duration,
            'step_results' => $stepResults,
            'condition_result' => $conditionResult,
            'result_summary' => ['output' => $lastOutput, 'notification' => $notifyResult],
            'execution_ref' => $executionRef,
            'planning_request_id' => $planningRequestId,
            'notification_status' => ($notifyResult['sent'] ?? false) ? 'sent' : (($notifyResult['delayed'] ?? false) ? 'delayed' : 'skipped'),
        ]);

        $this->advanceSchedule($automation);
        $this->repository->updateAutomation($automation, [
            'last_run_at' => now(),
            'last_run_status' => $status,
        ]);

        return new AgentAutomationRunResult(
            ok: true,
            status: $status,
            runHashId: (string) $run->hash_id,
            occurrenceKey: (string) $run->occurrence_key,
            summary: ['output' => $lastOutput],
            stepResults: $stepResults,
            conditionResult: $conditionResult,
            executionRef: $executionRef,
            planningRequestId: $planningRequestId,
        );
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function stepReadSkill(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        array $step,
    ): array {
        $skillKey = (string) ($step['skill_key'] ?? '');
        $skill = $this->skills->get($skillKey);
        if ($skill === null || $skill->isHidden) {
            return ['ok' => false, 'error' => 'capability_unavailable', 'error_category' => 'unsupported_capability'];
        }
        if ($skill->confirmationPolicy !== 'none') {
            // Read autonomous path requires none confirmation.
            return ['ok' => false, 'error' => 'read_requires_none_confirmation', 'error_category' => 'business_rule_violation'];
        }

        $result = $this->execution->execute(new AgentExecutionRequest(
            context: $context,
            conversation: $conversation,
            skillKey: $skillKey,
            formInput: is_array($step['input'] ?? null) ? $step['input'] : [],
            mode: 'execute',
        ));

        return [
            'ok' => $result->ok,
            'output' => $result->toArray(),
            'execution_ref' => $result->executionRef ?? null,
            'error' => $result->ok ? null : ($result->code ?? 'execution_failed'),
            'error_category' => $result->ok ? null : 'provider_error',
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function stepPlanning(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        array $step,
    ): array {
        $prompt = (string) ($step['prompt'] ?? '');
        $planned = $this->planning->plan(new AgentPlanningRequest(
            context: $context,
            conversation: $conversation,
            userMessage: $prompt,
            taskType: 'automation_planning',
            hints: ['source' => 'automation', 'proposal_only' => true],
        ));

        return [
            'ok' => true,
            'output' => $planned,
            'planning_request_id' => $planned['planning_request_id'] ?? $planned['id'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function stepExecutionPreview(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        SeoAgentAutomation $automation,
        SeoAgentAutomationRun $run,
        array $step,
    ): array {
        $skillKey = (string) ($step['skill_key'] ?? '');
        $preview = $this->execution->preview(new AgentExecutionRequest(
            context: $context,
            conversation: $conversation,
            skillKey: $skillKey,
            formInput: is_array($step['input'] ?? null) ? $step['input'] : [],
            mode: 'preview',
        ));

        $token = $this->approvalTokens->issue([
            'actor_id' => $context->actorUserId,
            'automation_id' => $automation->id,
            'run_id' => $run->id,
            'definition_version' => $automation->version,
            'definition_hash' => $automation->definition_hash,
            'site_ref' => $context->siteRef,
            'execution_ref' => $preview->executionRef ?? null,
            'hash' => null,
        ]);
        // store hash on bind for consume checks
        $token['hash'] = $token['hash'];

        $approval = $this->repository->createApproval(
            $automation,
            $run,
            $token['hash'],
            [
                'preview' => method_exists($preview, 'toArray') ? $preview->toArray() : (array) $preview,
                // raw token NEVER stored
            ],
            new DateTimeImmutable($token['expires_at']),
            $preview->executionRef ?? null,
        );

        return [
            'ok' => true,
            'waiting_for_approval' => true,
            'approval_hash_id' => $approval->hash_id,
            // One-time token returned only to notification/UI channel, not DB
            'approval_token_ephemeral' => $token['token'],
            'execution_ref' => $preview->executionRef ?? null,
            'output' => method_exists($preview, 'toArray') ? $preview->toArray() : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $lastOutput
     * @return array<string, mixed>
     */
    private function stepCondition(SeoAgentAutomation $automation, array $step, array $lastOutput): array
    {
        $condition = is_array($step['condition'] ?? null)
            ? $step['condition']
            : (is_array($automation->condition_json) ? $automation->condition_json : []);

        $baselineRow = $this->repository->getState((int) $automation->id, 'condition_baseline');
        $baseline = is_array($baselineRow?->payload) ? $baselineRow->payload : null;
        if ($baseline !== null && $baselineRow?->fingerprint) {
            $baseline['_fingerprint'] = $baselineRow->fingerprint;
        }

        $allowed = ['summary', 'result', 'data', 'status', 'count', 'items'];
        $evaluated = $this->conditions->evaluate($condition, $lastOutput, $baseline, $allowed);
        if ($evaluated->errors !== []) {
            return ['ok' => false, 'error' => implode(',', $evaluated->errors), 'error_category' => 'invalid_definition'];
        }

        $this->repository->putState(
            (int) $automation->id,
            'condition_baseline',
            $evaluated->fingerprint,
            array_merge($lastOutput, ['_fingerprint' => $evaluated->fingerprint]),
        );

        return [
            'ok' => true,
            'condition_result' => $evaluated->toArray(),
            'output' => $lastOutput,
        ];
    }

    private function rebuildContext(SeoAgentAutomation $automation): ?AgentWorkspaceContext
    {
        $user = User::query()->find($automation->owner_user_id);
        if ($user === null) {
            return null;
        }
        // Fail closed — no admin fallback. Minimal context from snapshot + owner.
        return new AgentWorkspaceContext(
            tenantRef: 'tenant:'.$automation->site_ref,
            siteRef: (string) $automation->site_ref,
            tenantId: (int) $automation->tenant_id,
            siteId: (int) $automation->site_id,
            connectionId: null,
            siteName: (string) $automation->site_ref,
            actorRef: 'user:'.$user->id,
            actorUserId: (int) $user->id,
            role: 'member',
            scopes: ['agent:automation'],
            projectRef: $automation->scope_type === 'project' ? $automation->scope_ref : null,
            workspaceRef: $automation->scope_type === 'workspace' ? $automation->scope_ref : null,
        );
    }

    private function resolveConversation(
        AgentWorkspaceContext $context,
        SeoAgentAutomation $automation,
    ): SeoAgentConversation {
        if ($automation->conversation_id) {
            $existing = SeoAgentConversation::query()->find($automation->conversation_id);
            if ($existing instanceof SeoAgentConversation
                && (int) $existing->site_id === $context->siteId) {
                return $existing;
            }
        }

        return $this->conversations->create($context, 'Automation: '.$automation->name);
    }

    private function advanceSchedule(SeoAgentAutomation $automation): void
    {
        $trigger = is_array($automation->trigger_json) ? $automation->trigger_json : [];
        $resolved = $this->schedules->resolve($trigger);
        if (($resolved['ok'] ?? false) && isset($resolved['next_run_at'])) {
            $this->repository->updateAutomation($automation, [
                'next_run_at' => $resolved['next_run_at'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function transition(SeoAgentAutomationRun $run, string $to, array $attrs = []): void
    {
        $from = (string) $run->status;
        if ($from !== $to) {
            $this->states->assertCanTransition($from, $to);
        }
        $this->repository->updateRun($run, array_merge(['status' => $to], $attrs));
        $run->refresh();
    }

    private function skip(
        SeoAgentAutomationRun $run,
        SeoAgentAutomation $automation,
        string $reason,
    ): AgentAutomationRunResult {
        try {
            $this->transition($run, 'skipped', [
                'skip_reason' => $reason,
                'finished_at' => now(),
            ]);
        } catch (Throwable) {
            $this->repository->updateRun($run, [
                'status' => 'skipped',
                'skip_reason' => $reason,
                'finished_at' => now(),
            ]);
        }
        $this->advanceSchedule($automation);

        return new AgentAutomationRunResult(
            ok: true,
            status: 'skipped',
            runHashId: (string) $run->hash_id,
            occurrenceKey: (string) $run->occurrence_key,
            skipReason: $reason,
        );
    }

    private function permissionLost(
        SeoAgentAutomationRun $run,
        SeoAgentAutomation $automation,
    ): AgentAutomationRunResult {
        $policy = is_array($automation->policy_json) ? $automation->policy_json : [];
        $action = (string) ($policy['permission_lost_action'] ?? 'pause');
        if ($action === 'pause') {
            $this->repository->updateAutomation($automation, [
                'status' => 'paused',
                'paused_at' => now(),
                'pause_reason' => 'permission_lost',
            ]);
        } else {
            $this->repository->updateAutomation($automation, [
                'status' => 'invalid',
                'enabled' => false,
                'pause_reason' => 'permission_lost',
            ]);
        }

        return $this->skip($run, $automation, 'permission_lost');
    }

    /**
     * @param  list<array<string, mixed>>  $stepResults
     * @param  array<string, mixed>  $error
     */
    private function fail(
        SeoAgentAutomationRun $run,
        SeoAgentAutomation $automation,
        array $stepResults,
        string $category,
        array $error,
    ): AgentAutomationRunResult {
        $policy = is_array($automation->policy_json) ? $automation->policy_json : [];
        $maxAttempts = (int) ($policy['max_attempts'] ?? 3);
        $attempt = (int) $run->attempt;

        try {
            $this->transition($run, 'failed', [
                'finished_at' => now(),
                'step_results' => $stepResults,
                'error_category' => $category,
                'error_payload' => $error,
            ]);
        } catch (Throwable) {
            $this->repository->updateRun($run, [
                'status' => 'failed',
                'finished_at' => now(),
                'error_category' => $category,
                'error_payload' => $error,
                'step_results' => $stepResults,
            ]);
        }

        if (in_array($category, self::RETRYABLE, true) && $attempt < $maxAttempts) {
            // Preserve occurrence; increment attempt and requeue
            $this->repository->updateRun($run->refresh(), [
                'attempt' => $attempt + 1,
                'status' => 'queued',
                'finished_at' => null,
                'error_category' => $category,
            ]);
        } else {
            $this->advanceSchedule($automation);
        }

        $this->repository->updateAutomation($automation, [
            'last_run_at' => now(),
            'last_run_status' => 'failed',
        ]);

        return new AgentAutomationRunResult(
            ok: false,
            status: 'failed',
            runHashId: (string) $run->hash_id,
            occurrenceKey: (string) $run->occurrence_key,
            stepResults: $stepResults,
            error: array_merge($error, ['category' => $category]),
        );
    }
}
