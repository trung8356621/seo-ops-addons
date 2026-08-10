<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentAutomation;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentAutomationApproval;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentAutomationRun;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentAutomationState;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationRepository;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationDefinitionData;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

final class EloquentAgentAutomationRepository implements AgentAutomationRepository
{
    public function create(AgentWorkspaceContext $context, AgentAutomationDefinitionData $definition): SeoAgentAutomation
    {
        return SeoAgentAutomation::query()->create([
            'hash_id' => 'aauto_'.Str::lower((string) Str::ulid()),
            'connection_hash' => $context->connectionId !== null ? hash('sha256', (string) $context->connectionId) : null,
            'tenant_id' => $context->tenantId,
            'site_id' => $context->siteId,
            'site_ref' => $context->siteRef,
            'owner_user_id' => $context->actorUserId,
            'name' => $definition->name,
            'description' => $definition->description,
            'type' => $definition->type,
            'scope_type' => $definition->scopeType,
            'scope_ref' => $definition->scopeRef,
            'status' => $definition->enabled ? 'active' : 'disabled',
            'enabled' => $definition->enabled,
            'version' => 1,
            'definition_hash' => $definition->definitionHash,
            'trigger_json' => $definition->trigger,
            'workflow_json' => $definition->workflow,
            'condition_json' => $definition->condition,
            'notification_json' => $definition->notification,
            'policy_json' => $definition->policy ?? [
                'auto_execute_safe_writes' => false,
                'catch_up' => 'skip_missed',
                'require_confirmation' => true,
            ],
            'timezone' => $definition->timezone,
            'next_run_at' => $definition->trigger['resolved_next_run_at'] ?? null,
            'conversation_id' => $definition->conversationId,
        ]);
    }

    public function update(SeoAgentAutomation $automation, AgentAutomationDefinitionData $definition): SeoAgentAutomation
    {
        $automation->fill([
            'name' => $definition->name,
            'description' => $definition->description,
            'type' => $definition->type,
            'scope_type' => $definition->scopeType,
            'scope_ref' => $definition->scopeRef,
            'enabled' => $definition->enabled,
            'status' => $definition->enabled
                ? ($automation->status === 'paused' ? 'paused' : 'active')
                : 'disabled',
            'version' => (int) $automation->version + 1,
            'definition_hash' => $definition->definitionHash,
            'trigger_json' => $definition->trigger,
            'workflow_json' => $definition->workflow,
            'condition_json' => $definition->condition,
            'notification_json' => $definition->notification,
            'policy_json' => $definition->policy,
            'timezone' => $definition->timezone,
            'next_run_at' => $definition->trigger['resolved_next_run_at'] ?? $automation->next_run_at,
            'conversation_id' => $definition->conversationId ?? $automation->conversation_id,
        ]);
        $automation->save();

        return $automation->refresh();
    }

    public function findByHash(string $hashId): ?SeoAgentAutomation
    {
        return SeoAgentAutomation::query()->where('hash_id', $hashId)->first();
    }

    public function listForContext(AgentWorkspaceContext $context): array
    {
        return SeoAgentAutomation::query()
            ->where('site_id', $context->siteId)
            ->where('tenant_id', $context->tenantId)
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->all();
    }

    public function findDue(DateTimeInterface $nowUtc, int $limit = 100): array
    {
        return SeoAgentAutomation::query()
            ->where('enabled', true)
            ->where('status', 'active')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $nowUtc)
            ->orderBy('next_run_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function claimOccurrence(
        SeoAgentAutomation $automation,
        string $occurrenceKey,
        DateTimeInterface $scheduledAt,
        string $triggerSource,
        string $status = 'queued',
    ): SeoAgentAutomationRun {
        $idempotency = 'aauto_run_'.hash('sha256', $occurrenceKey.'|'.$automation->definition_hash);

        try {
            return SeoAgentAutomationRun::query()->create([
                'hash_id' => 'aarun_'.Str::lower((string) Str::ulid()),
                'automation_id' => $automation->id,
                'occurrence_key' => $occurrenceKey,
                'idempotency_key' => $idempotency,
                'status' => $status,
                'attempt' => 1,
                'definition_version' => (int) $automation->version,
                'definition_hash' => (string) $automation->definition_hash,
                'scheduled_at' => $scheduledAt,
                'trigger_source' => $triggerSource,
            ]);
        } catch (QueryException $e) {
            $existing = SeoAgentAutomationRun::query()
                ->where('automation_id', $automation->id)
                ->where('occurrence_key', $occurrenceKey)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }
    }

    public function findRunById(int $runId): ?SeoAgentAutomationRun
    {
        return SeoAgentAutomationRun::query()->find($runId);
    }

    public function findRunByHash(string $hashId): ?SeoAgentAutomationRun
    {
        return SeoAgentAutomationRun::query()->where('hash_id', $hashId)->first();
    }

    public function listRuns(int $automationId, int $limit = 50): array
    {
        return SeoAgentAutomationRun::query()
            ->where('automation_id', $automationId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function updateRun(SeoAgentAutomationRun $run, array $attrs): SeoAgentAutomationRun
    {
        $run->fill($attrs);
        $run->save();

        return $run->refresh();
    }

    public function updateAutomation(SeoAgentAutomation $automation, array $attrs): SeoAgentAutomation
    {
        $automation->fill($attrs);
        $automation->save();

        return $automation->refresh();
    }

    public function createApproval(
        SeoAgentAutomation $automation,
        SeoAgentAutomationRun $run,
        string $tokenHash,
        array $previewPayload,
        DateTimeInterface $expiresAt,
        ?string $executionRef = null,
    ): SeoAgentAutomationApproval {
        return SeoAgentAutomationApproval::query()->create([
            'hash_id' => 'aaapr_'.Str::lower((string) Str::ulid()),
            'automation_id' => $automation->id,
            'run_id' => $run->id,
            'actor_user_id' => (int) $automation->owner_user_id,
            'site_ref' => (string) $automation->site_ref,
            'definition_version' => (int) $automation->version,
            'definition_hash' => (string) $automation->definition_hash,
            'token_hash' => $tokenHash,
            'status' => 'pending',
            'preview_payload' => $previewPayload,
            'execution_ref' => $executionRef,
            'expires_at' => $expiresAt,
        ]);
    }

    public function findApprovalByHash(string $hashId): ?SeoAgentAutomationApproval
    {
        return SeoAgentAutomationApproval::query()->where('hash_id', $hashId)->first();
    }

    public function getState(int $automationId, string $stateKey): ?SeoAgentAutomationState
    {
        return SeoAgentAutomationState::query()
            ->where('automation_id', $automationId)
            ->where('state_key', $stateKey)
            ->first();
    }

    public function putState(
        int $automationId,
        string $stateKey,
        ?string $fingerprint,
        ?array $payload,
    ): SeoAgentAutomationState {
        $state = $this->getState($automationId, $stateKey);
        if ($state === null) {
            return SeoAgentAutomationState::query()->create([
                'automation_id' => $automationId,
                'state_key' => $stateKey,
                'fingerprint' => $fingerprint,
                'payload' => $payload,
                'observed_at' => now(),
            ]);
        }
        $state->fill([
            'fingerprint' => $fingerprint,
            'payload' => $payload,
            'observed_at' => now(),
        ]);
        $state->save();

        return $state->refresh();
    }

    public function countActiveForSite(int $siteId): int
    {
        return (int) SeoAgentAutomation::query()
            ->where('site_id', $siteId)
            ->where('enabled', true)
            ->whereIn('status', ['active', 'paused'])
            ->count();
    }

    public function countForOwner(int $ownerUserId): int
    {
        return (int) SeoAgentAutomation::query()
            ->where('owner_user_id', $ownerUserId)
            ->count();
    }

    public function countRunsSince(int $automationId, DateTimeInterface $since): int
    {
        return (int) SeoAgentAutomationRun::query()
            ->where('automation_id', $automationId)
            ->where('created_at', '>=', $since)
            ->count();
    }

    public function countConcurrentRunning(int $siteId): int
    {
        return (int) SeoAgentAutomationRun::query()
            ->whereIn('status', ['queued', 'running', 'waiting_for_approval'])
            ->whereHas('automation', static function ($q) use ($siteId): void {
                $q->where('site_id', $siteId);
            })
            ->count();
    }

    public function softDelete(SeoAgentAutomation $automation): void
    {
        $automation->enabled = false;
        $automation->status = 'deleted';
        $automation->save();
        $automation->delete();
    }
}
