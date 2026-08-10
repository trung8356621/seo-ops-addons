<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services;

use Omnichannel\Addons\Agent\Jobs\RunAgentAutomationJob;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentAutomation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationRepository;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationScheduleResolver;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationControlRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationDefinitionRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationDefinitionResult;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationPreview;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationRunRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationRunResult;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationUpdateRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

final class DefaultAgentAutomationOrchestrator implements AgentAutomationOrchestrator
{
    public function __construct(
        private readonly AgentAutomationDefinitionValidator $validator,
        private readonly AgentAutomationRepository $repository,
        private readonly AgentAutomationScheduleResolver $schedules,
        private readonly AgentAutomationQuotaService $quotas,
        private readonly AgentAutomationLockService $locks,
        private readonly AgentAutomationApprovalTokenService $approvalTokens,
        private readonly AgentAutomationRunStateMachine $states,
    ) {}

    public function previewDefinition(
        AgentWorkspaceContext $context,
        AgentAutomationDefinitionRequest $request,
    ): AgentAutomationPreview {
        $validated = $this->validator->validate($context, $request);
        $definition = $validated['definition'] ?? null;
        $schedule = $validated['schedule'] ?? [];
        $warnings = $validated['warnings'] ?? $validated['errors'] ?? [];

        $writeEffects = [];
        $capabilities = $validated['capabilities'] ?? [];
        $steps = $definition?->workflow ?? $request->workflow;
        foreach ($steps as $step) {
            if (($step['type'] ?? '') === 'execution_preview') {
                $writeEffects[] = (string) ($step['skill_key'] ?? 'write');
            }
        }

        return new AgentAutomationPreview(
            name: $request->name,
            type: $request->type,
            siteRef: $context->siteRef,
            scopeLabel: ($request->scopeType).':'.($request->scopeRef ?? $context->siteRef),
            scheduleSummary: is_array($schedule['normalized'] ?? null) ? $schedule['normalized'] : $request->trigger,
            nextRuns: is_array($schedule['preview_occurrences'] ?? null) ? $schedule['preview_occurrences'] : [],
            workflowSteps: $steps,
            conditionSummary: $request->condition,
            notificationSummary: $request->notification,
            quietHours: is_array(($schedule['normalized']['quiet_hours'] ?? null))
                ? $schedule['normalized']['quiet_hours']
                : null,
            permissions: [
                'actor_user_id' => $context->actorUserId,
                'role' => $context->role,
                'scopes' => $context->scopes,
            ],
            capabilities: $capabilities,
            writeEffects: $writeEffects,
            quotaEstimate: [
                'active_on_site' => $this->repository->countActiveForSite($context->siteId),
                'max_active_per_site' => $this->quotas->maxActivePerSite(),
                'owner_count' => $this->repository->countForOwner($context->actorUserId),
                'max_per_user' => $this->quotas->maxPerUser(),
            ],
            warnings: array_values(array_unique(array_map('strval', $warnings))),
            requiresExplicitSave: true,
        );
    }

    public function create(
        AgentWorkspaceContext $context,
        AgentAutomationDefinitionRequest $request,
        bool $explicitSave,
    ): AgentAutomationDefinitionResult {
        if (! $explicitSave) {
            return new AgentAutomationDefinitionResult(
                ok: false,
                preview: $this->previewDefinition($context, $request),
                errors: ['explicit_save_required'],
                code: 'preview_only',
            );
        }

        $validated = $this->validator->validate($context, $request);
        if (! ($validated['ok'] ?? false) || ! isset($validated['definition'])) {
            return new AgentAutomationDefinitionResult(
                ok: false,
                preview: $this->previewDefinition($context, $request),
                errors: $validated['errors'] ?? ['invalid_definition'],
                code: 'validation_failed',
            );
        }

        if ($this->repository->countActiveForSite($context->siteId) >= $this->quotas->maxActivePerSite()) {
            return new AgentAutomationDefinitionResult(
                ok: false,
                errors: ['quota_exceeded_site'],
                code: 'quota_exceeded',
            );
        }
        if ($this->repository->countForOwner($context->actorUserId) >= $this->quotas->maxPerUser()) {
            return new AgentAutomationDefinitionResult(
                ok: false,
                errors: ['quota_exceeded_user'],
                code: 'quota_exceeded',
            );
        }

        $automation = $this->repository->create($context, $validated['definition']);

        return new AgentAutomationDefinitionResult(
            ok: true,
            automation: $this->serializeAutomation($automation),
            preview: $this->previewDefinition($context, $request),
            warnings: $validated['warnings'] ?? [],
            code: 'created',
        );
    }

    public function update(
        AgentWorkspaceContext $context,
        AgentAutomationUpdateRequest $request,
        bool $explicitSave,
    ): AgentAutomationDefinitionResult {
        $automation = $this->findAuthorized($context, $request->automationHashId);
        if ($automation === null) {
            return new AgentAutomationDefinitionResult(ok: false, errors: ['not_found'], code: 'not_found');
        }
        if (! $explicitSave) {
            return new AgentAutomationDefinitionResult(
                ok: false,
                preview: $this->previewDefinition($context, $request->definition),
                errors: ['explicit_save_required'],
                code: 'preview_only',
            );
        }
        $validated = $this->validator->validate($context, $request->definition);
        if (! ($validated['ok'] ?? false) || ! isset($validated['definition'])) {
            return new AgentAutomationDefinitionResult(
                ok: false,
                errors: $validated['errors'] ?? ['invalid_definition'],
                code: 'validation_failed',
            );
        }
        $automation = $this->repository->update($automation, $validated['definition']);

        return new AgentAutomationDefinitionResult(
            ok: true,
            automation: $this->serializeAutomation($automation),
            warnings: $validated['warnings'] ?? [],
            code: 'updated',
        );
    }

    public function control(
        AgentWorkspaceContext $context,
        AgentAutomationControlRequest $request,
    ): AgentAutomationDefinitionResult {
        $automation = $this->findAuthorized($context, $request->automationHashId);
        if ($automation === null) {
            return new AgentAutomationDefinitionResult(ok: false, errors: ['not_found'], code: 'not_found');
        }

        return match ($request->action) {
            AgentAutomationControlRequest::ACTION_PAUSE => $this->pause($automation, $request->reason),
            AgentAutomationControlRequest::ACTION_RESUME => $this->resume($context, $automation, $request->catchUpPolicy),
            AgentAutomationControlRequest::ACTION_DELETE => $this->delete($automation),
            AgentAutomationControlRequest::ACTION_ENABLE => $this->setEnabled($automation, true),
            AgentAutomationControlRequest::ACTION_DISABLE => $this->setEnabled($automation, false),
            default => new AgentAutomationDefinitionResult(ok: false, errors: ['unknown_action'], code: 'unknown_action'),
        };
    }

    public function runNow(
        AgentWorkspaceContext $context,
        AgentAutomationRunRequest $request,
    ): AgentAutomationRunResult {
        $automation = $this->findAuthorized($context, $request->automationHashId);
        if ($automation === null) {
            return new AgentAutomationRunResult(ok: false, status: 'failed', runHashId: '', error: ['code' => 'not_found']);
        }
        if (! $automation->enabled || $automation->status === 'paused') {
            return new AgentAutomationRunResult(
                ok: false,
                status: 'skipped',
                runHashId: '',
                skipReason: $automation->status === 'paused' ? 'paused' : 'disabled',
            );
        }

        $scheduledAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $scheduledKey = $scheduledAt->format('Y-m-d\TH:i:00\Z');
        $occurrenceKey = $this->locks->occurrenceKey((int) $automation->id, $scheduledKey.'|manual|'.Str::lower((string) Str::ulid()));

        $run = $this->repository->claimOccurrence(
            $automation,
            $occurrenceKey,
            $scheduledAt,
            $request->triggerSource,
            'queued',
        );

        RunAgentAutomationJob::dispatch((int) $run->id);

        return new AgentAutomationRunResult(
            ok: true,
            status: (string) $run->status,
            runHashId: (string) $run->hash_id,
            occurrenceKey: (string) $run->occurrence_key,
        );
    }

    public function list(AgentWorkspaceContext $context): array
    {
        return array_map(
            fn (SeoAgentAutomation $a): array => $this->serializeAutomation($a),
            $this->repository->listForContext($context),
        );
    }

    public function get(AgentWorkspaceContext $context, string $automationHashId): ?array
    {
        $automation = $this->findAuthorized($context, $automationHashId);

        return $automation !== null ? $this->serializeAutomation($automation) : null;
    }

    public function history(AgentWorkspaceContext $context, string $automationHashId, int $limit = 50): array
    {
        $automation = $this->findAuthorized($context, $automationHashId);
        if ($automation === null) {
            return [];
        }

        return array_map(static function ($run): array {
            return [
                'hash_id' => $run->hash_id,
                'occurrence_key' => $run->occurrence_key,
                'status' => $run->status,
                'skip_reason' => $run->skip_reason,
                'attempt' => $run->attempt,
                'scheduled_at' => optional($run->scheduled_at)?->toIso8601String(),
                'started_at' => optional($run->started_at)?->toIso8601String(),
                'finished_at' => optional($run->finished_at)?->toIso8601String(),
                'duration_ms' => $run->duration_ms,
                'condition_result' => $run->condition_result,
                'result_summary' => $run->result_summary,
                'notification_status' => $run->notification_status,
                'execution_ref' => $run->execution_ref,
                'planning_request_id' => $run->planning_request_id,
                'error_category' => $run->error_category,
                'error_payload' => $run->error_payload,
                'trigger_source' => $run->trigger_source,
            ];
        }, $this->repository->listRuns((int) $automation->id, $limit));
    }

    public function approveRun(
        AgentWorkspaceContext $context,
        string $approvalHashId,
        string $rawToken,
    ): array {
        // AI cannot approve — caller must be human actor with matching context.
        if ($rawToken === '' || str_starts_with($rawToken, 'ai:')) {
            return ['ok' => false, 'code' => 'ai_cannot_approve'];
        }

        $approval = $this->repository->findApprovalByHash($approvalHashId);
        if ($approval === null) {
            return ['ok' => false, 'code' => 'approval_not_found'];
        }
        if ((string) $approval->status !== 'pending') {
            return ['ok' => false, 'code' => 'approval_not_pending'];
        }
        if ($approval->expires_at !== null && $approval->expires_at->isPast()) {
            $approval->status = 'expired';
            $approval->save();

            return ['ok' => false, 'code' => 'expired_token'];
        }
        if ((int) $approval->actor_user_id !== $context->actorUserId) {
            return ['ok' => false, 'code' => 'actor_mismatch'];
        }
        if ((string) $approval->site_ref !== $context->siteRef) {
            return ['ok' => false, 'code' => 'site_mismatch'];
        }

        if ($this->approvalTokens->hashToken($rawToken) !== (string) $approval->token_hash) {
            return ['ok' => false, 'code' => 'invalid_token'];
        }
        $payload = $this->approvalTokens->consume($rawToken);
        if ($payload === null) {
            return ['ok' => false, 'code' => 'expired_token'];
        }

        $automation = SeoAgentAutomation::query()->find($approval->automation_id);
        if ($automation === null) {
            return ['ok' => false, 'code' => 'automation_missing'];
        }
        if ((string) $automation->definition_hash !== (string) $approval->definition_hash
            || (int) $automation->version !== (int) $approval->definition_version) {
            return ['ok' => false, 'code' => 'stale_definition'];
        }

        $approval->status = 'approved';
        $approval->resolved_at = now();
        $approval->resolved_by = $context->actorUserId;
        $approval->save();

        // Phase 2 confirmation still required independently when execution_ref present.
        return [
            'ok' => true,
            'code' => 'approved',
            'approval_hash_id' => $approval->hash_id,
            'execution_ref' => $approval->execution_ref,
            'run_id' => $approval->run_id,
            'message' => 'Automation approval recorded. Phase 2 confirmation policy still applies.',
            'requires_phase2_confirm' => $approval->execution_ref !== null,
        ];
    }

    public function diagnostics(AgentWorkspaceContext $context, string $automationHashId): array
    {
        if (! in_array($context->role, ['admin', 'manager', 'owner'], true) && ! in_array('agent:diagnostics', $context->scopes, true)) {
            return ['ok' => false, 'code' => 'forbidden'];
        }
        $automation = $this->findAuthorized($context, $automationHashId);
        if ($automation === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        $schedule = $this->schedules->resolve(is_array($automation->trigger_json) ? $automation->trigger_json : []);

        return [
            'ok' => true,
            'automation_hash_id' => $automation->hash_id,
            'definition_version' => $automation->version,
            'definition_hash' => $automation->definition_hash,
            'owner_user_id' => $automation->owner_user_id,
            'site_ref' => $automation->site_ref,
            'status' => $automation->status,
            'next_run_at' => optional($automation->next_run_at)?->toIso8601String(),
            'occurrences' => $schedule['preview_occurrences'] ?? [],
            'lock_key' => $this->locks->key((int) $automation->id),
            'quota' => [
                'active_site' => $this->repository->countActiveForSite((int) $automation->site_id),
                'concurrent' => $this->repository->countConcurrentRunning((int) $automation->site_id),
            ],
            // no secrets / raw tokens / prompts
        ];
    }

    private function pause(SeoAgentAutomation $automation, ?string $reason): AgentAutomationDefinitionResult
    {
        $this->repository->updateAutomation($automation, [
            'status' => 'paused',
            'paused_at' => now(),
            'pause_reason' => $reason ?? 'user_paused',
        ]);

        return new AgentAutomationDefinitionResult(
            ok: true,
            automation: $this->serializeAutomation($automation->refresh()),
            code: 'paused',
        );
    }

    private function resume(
        AgentWorkspaceContext $context,
        SeoAgentAutomation $automation,
        string $catchUpPolicy,
    ): AgentAutomationDefinitionResult {
        // Revalidate schedule + scope
        if ((int) $automation->site_id !== $context->siteId) {
            return new AgentAutomationDefinitionResult(ok: false, errors: ['scope_invalid'], code: 'scope_invalid');
        }
        $schedule = $this->schedules->resolve(is_array($automation->trigger_json) ? $automation->trigger_json : []);
        if (! ($schedule['ok'] ?? false)) {
            return new AgentAutomationDefinitionResult(ok: false, errors: $schedule['errors'] ?? ['invalid_schedule'], code: 'invalid_definition');
        }

        $next = $schedule['next_run_at'] ?? null;
        if ($catchUpPolicy === 'run_once' && $automation->next_run_at !== null && $automation->next_run_at->isPast()) {
            $next = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
        }

        $this->repository->updateAutomation($automation, [
            'status' => 'active',
            'enabled' => true,
            'paused_at' => null,
            'pause_reason' => null,
            'next_run_at' => $next,
        ]);

        return new AgentAutomationDefinitionResult(
            ok: true,
            automation: $this->serializeAutomation($automation->refresh()),
            code: 'resumed',
        );
    }

    private function delete(SeoAgentAutomation $automation): AgentAutomationDefinitionResult
    {
        // Cancel unstarted queued runs when safe
        foreach ($this->repository->listRuns((int) $automation->id, 20) as $run) {
            if (in_array((string) $run->status, ['pending', 'queued'], true)) {
                try {
                    $this->states->assertCanTransition((string) $run->status, 'cancelled');
                    $this->repository->updateRun($run, ['status' => 'cancelled', 'finished_at' => now()]);
                } catch (\Throwable) {
                }
            }
        }
        $this->repository->softDelete($automation);

        return new AgentAutomationDefinitionResult(ok: true, code: 'deleted');
    }

    private function setEnabled(SeoAgentAutomation $automation, bool $enabled): AgentAutomationDefinitionResult
    {
        $this->repository->updateAutomation($automation, [
            'enabled' => $enabled,
            'status' => $enabled ? 'active' : 'disabled',
        ]);

        return new AgentAutomationDefinitionResult(
            ok: true,
            automation: $this->serializeAutomation($automation->refresh()),
            code: $enabled ? 'enabled' : 'disabled',
        );
    }

    private function findAuthorized(AgentWorkspaceContext $context, string $hashId): ?SeoAgentAutomation
    {
        $automation = $this->repository->findByHash($hashId);
        if ($automation === null) {
            return null;
        }
        if ((int) $automation->site_id !== $context->siteId || (int) $automation->tenant_id !== $context->tenantId) {
            return null;
        }
        // Owner or manager role may manage; no cross-site.
        if ((int) $automation->owner_user_id !== $context->actorUserId
            && ! in_array($context->role, ['admin', 'manager', 'owner'], true)) {
            return null;
        }

        return $automation;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAutomation(SeoAgentAutomation $a): array
    {
        return [
            'hash_id' => $a->hash_id,
            'name' => $a->name,
            'description' => $a->description,
            'type' => $a->type,
            'status' => $a->status,
            'enabled' => (bool) $a->enabled,
            'scope_type' => $a->scope_type,
            'scope_ref' => $a->scope_ref,
            'timezone' => $a->timezone,
            'next_run_at' => optional($a->next_run_at)?->toIso8601String(),
            'last_run_at' => optional($a->last_run_at)?->toIso8601String(),
            'last_run_status' => $a->last_run_status,
            'owner_user_id' => $a->owner_user_id,
            'version' => $a->version,
            'definition_hash' => $a->definition_hash,
            'trigger' => $a->trigger_json,
            'workflow' => $a->workflow_json,
            'condition' => $a->condition_json,
            'notification' => $a->notification_json,
            'policy' => $a->policy_json,
            'approval_mode' => 'guarded',
        ];
    }
}
