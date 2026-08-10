<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

use Omnichannel\Addons\ContentProjects\Models\ContentProjectAutomationPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentErrorCodes;

/**
 * Resolve automation policy and enforce capability / automation level gates.
 */
final class ContentProjectAutomationPolicyService
{
    /** @var list<string> */
    private const HARD_CONFIRMATION = [
        'content_project.archive',
        'content_project.restore',
        'content_project.publish_now',
        'content_project.cancel_publish',
        'content_project.skip_publish',
    ];

    /** @var list<string> */
    private const REVIEWED_STOP = [
        'content_project.approve',
        'content_project.publish_now',
        'content_project.archive',
        'content_project.restore',
    ];

    public function resolveForTenant(int $tenantId, ?int $siteId = null): ?ContentProjectAutomationPolicy
    {
        $query = ContentProjectAutomationPolicy::query()
            ->where('tenant_id', $tenantId)
            ->where('enabled', true);

        if ($siteId !== null && $siteId > 0) {
            $query->where(function ($q) use ($siteId): void {
                $q->whereNull('site_id')->orWhere('site_id', $siteId);
            })->orderByRaw('site_id IS NULL ASC');
        }

        return $query->orderByDesc('id')->first();
    }

    public function findByRef(string $policyRef): ?ContentProjectAutomationPolicy
    {
        try {
            $id = ContentProjectAgentPlanRef::decodePolicy($policyRef);
        } catch (\Throwable) {
            return null;
        }

        return ContentProjectAutomationPolicy::query()->find($id);
    }

    public function isCapabilityAllowed(ContentProjectAutomationPolicy $policy, string $capability): bool
    {
        $blocked = is_array($policy->blocked_capabilities) ? $policy->blocked_capabilities : [];
        if (in_array($capability, $blocked, true)) {
            return false;
        }

        $allowed = is_array($policy->allowed_capabilities) ? $policy->allowed_capabilities : [];
        if ($allowed !== [] && ! in_array($capability, $allowed, true)) {
            return false;
        }

        return true;
    }

    public function requiresConfirmation(ContentProjectAutomationPolicy $policy, string $capability): bool
    {
        if (in_array($capability, self::HARD_CONFIRMATION, true)) {
            return true;
        }

        $required = is_array($policy->require_confirmation_for) ? $policy->require_confirmation_for : [];

        return in_array($capability, $required, true);
    }

    public function canAutoRunStep(ContentProjectAutomationPolicy $policy, string $capability): bool
    {
        $level = (string) $policy->automation_level;

        if ($level === AgentAutomationLevel::MANUAL) {
            return false;
        }

        if ($level === AgentAutomationLevel::ASSISTED && $this->isImportantWrite($capability)) {
            return false;
        }

        if ($level === AgentAutomationLevel::REVIEWED_AUTOMATION && in_array($capability, self::REVIEWED_STOP, true)) {
            return false;
        }

        if (in_array($capability, self::HARD_CONFIRMATION, true)) {
            return false;
        }

        return true;
    }

    /**
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    public function previewForContext(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext $context): array
    {
        $tenantId = (int) ($context->resolvedSiteId ?? 0);
        $policy = $tenantId > 0 ? $this->resolveForTenant($tenantId, $tenantId) : null;

        if ($policy === null) {
            return [
                'success' => true,
                'code' => 'policy.default',
                'message' => 'No tenant policy; hard safety gates still apply.',
                'data' => [
                    'automation_level' => AgentAutomationLevel::MANUAL,
                    'hard_confirmation' => self::HARD_CONFIRMATION,
                    'enabled' => false,
                ],
            ];
        }

        return [
            'success' => true,
            'code' => 'policy.ok',
            'message' => 'Policy resolved.',
            'data' => [
                'policy_ref' => ContentProjectAgentPlanRef::policy((int) $policy->getKey()),
                'automation_level' => (string) $policy->automation_level,
                'enabled' => (bool) $policy->enabled,
                'allowed_capabilities' => $policy->allowed_capabilities,
                'blocked_capabilities' => $policy->blocked_capabilities,
                'require_confirmation_for' => $policy->require_confirmation_for,
                'hard_confirmation' => self::HARD_CONFIRMATION,
            ],
        ];
    }

    public function gateCodeForBlocked(string $capability): string
    {
        if (in_array($capability, self::HARD_CONFIRMATION, true)) {
            return AgentErrorCodes::CONFIRMATION_REQUIRED;
        }

        return AgentErrorCodes::PLAN_POLICY_DENIED;
    }

    private function isImportantWrite(string $capability): bool
    {
        return str_contains($capability, 'generate')
            || str_contains($capability, 'approve')
            || str_contains($capability, 'schedule')
            || str_contains($capability, 'publish')
            || str_contains($capability, 'archive')
            || str_contains($capability, 'restore');
    }
}
