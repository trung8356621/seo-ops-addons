<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

use Omnichannel\Addons\ContentProjects\Models\ContentProjectAgentApproval;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectMetricKeys;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectOpsMetrics;
use Illuminate\Support\Str;

/**
 * Create / approve / reject / expire agent approvals — one-time, tenant scoped.
 */
final class ContentProjectAgentApprovalService
{
    public function __construct(
        private readonly ContentProjectOpsMetrics $metrics,
    ) {}

    /**
     * @param  array<string, mixed>  $previewPayload
     */
    public function create(
        string $planRef,
        ?string $stepRef,
        int $tenantId,
        ?int $siteId,
        string $actorRef,
        string $action,
        string $summary,
        string $riskLevel,
        array $previewPayload,
        string $stateFingerprint,
        int $ttlMinutes = 60,
    ): ContentProjectAgentApproval {
        $approval = ContentProjectAgentApproval::query()->create([
            'public_ref' => 'apv_'.Str::lower((string) Str::ulid()),
            'plan_ref' => $planRef,
            'step_ref' => $stepRef,
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'actor_ref' => $actorRef,
            'action' => $action,
            'summary' => $summary,
            'risk_level' => $riskLevel,
            'preview_payload' => $previewPayload,
            'status' => AgentApprovalStatus::PENDING,
            'state_fingerprint' => $stateFingerprint,
            'expires_at' => now()->addMinutes(max(1, $ttlMinutes)),
        ]);

        $approval->public_ref = ContentProjectAgentPlanRef::approval((int) $approval->id);
        $approval->save();

        $this->metrics->increment(ContentProjectMetricKeys::AGENT_APPROVAL_REQUESTED_TOTAL, 1, $siteId);

        return $approval;
    }

    public function findPendingForStep(string $planRef, string $stepRef): ?ContentProjectAgentApproval
    {
        return ContentProjectAgentApproval::query()
            ->where('plan_ref', $planRef)
            ->where('step_ref', $stepRef)
            ->where('status', AgentApprovalStatus::PENDING)
            ->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    public function approve(string $approvalRef, string $approvedByRef, int $tenantId, string $expectedFingerprint): ?ContentProjectAgentApproval
    {
        $approval = $this->findByRef($approvalRef, $tenantId);
        if ($approval === null || $approval->status !== AgentApprovalStatus::PENDING) {
            return null;
        }

        if ($approval->state_fingerprint !== $expectedFingerprint) {
            return null;
        }

        if ($approval->expires_at !== null && now()->greaterThan($approval->expires_at)) {
            $approval->status = AgentApprovalStatus::EXPIRED;
            $approval->save();

            return null;
        }

        $approval->status = AgentApprovalStatus::APPROVED;
        $approval->approved_at = now();
        $approval->approved_by_ref = $approvedByRef;
        $approval->save();

        return $approval;
    }

    public function reject(string $approvalRef, string $rejectedByRef, int $tenantId): ?ContentProjectAgentApproval
    {
        $approval = $this->findByRef($approvalRef, $tenantId);
        if ($approval === null || $approval->status !== AgentApprovalStatus::PENDING) {
            return null;
        }

        $approval->status = AgentApprovalStatus::REJECTED;
        $approval->rejected_at = now();
        $approval->approved_by_ref = $rejectedByRef;
        $approval->save();

        $this->metrics->increment(ContentProjectMetricKeys::AGENT_APPROVAL_REJECTED_TOTAL, 1, $approval->site_id !== null ? (int) $approval->site_id : null);

        return $approval;
    }

    public function expireStale(): int
    {
        return ContentProjectAgentApproval::query()
            ->where('status', AgentApprovalStatus::PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => AgentApprovalStatus::EXPIRED]);
    }

    /**
     * @return list<ContentProjectAgentApproval>
     */
    public function listPending(int $tenantId, ?int $siteId = null, int $limit = 50): array
    {
        $query = ContentProjectAgentApproval::query()
            ->where('tenant_id', $tenantId)
            ->where('status', AgentApprovalStatus::PENDING)
            ->orderByDesc('id')
            ->limit($limit);

        if ($siteId !== null && $siteId > 0) {
            $query->where(function ($q) use ($siteId): void {
                $q->whereNull('site_id')->orWhere('site_id', $siteId);
            });
        }

        return $query->get()->all();
    }

    private function findByRef(string $approvalRef, int $tenantId): ?ContentProjectAgentApproval
    {
        try {
            $id = ContentProjectAgentPlanRef::decodeApproval($approvalRef);
        } catch (\Throwable) {
            return null;
        }

        return ContentProjectAgentApproval::query()
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();
    }
}
