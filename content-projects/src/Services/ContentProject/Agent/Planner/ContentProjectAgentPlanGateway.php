<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentCapabilityResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentErrorCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;

/**
 * Thin gateway for plan MCP tools — routes to Planner/ApplicationService, never CommandBus.
 */
final class ContentProjectAgentPlanGateway
{
    /** @var list<string> */
    public const PLAN_TOOLS = [
        'content_project.plan',
        'content_project.confirm_plan',
        'content_project.start_plan',
        'content_project.pause_plan',
        'content_project.resume_plan',
        'content_project.cancel_plan',
        'content_project.retry_plan_step',
        'content_project.get_agent_plan',
        'content_project.list_agent_plans',
        'content_project.get_agent_policy',
        'content_project.list_pending_approvals',
        'content_project.approve_agent_action',
        'content_project.reject_agent_action',
        // aliases
        'content_project.plan.create_draft',
        'content_project.plan.confirm',
        'content_project.plan.start',
        'content_project.plan.pause',
        'content_project.plan.resume',
        'content_project.plan.cancel',
        'content_project.plan.get',
        'content_project.plan.list',
        'content_project.plan.retry_step',
        'content_project.approval.approve',
        'content_project.approval.reject',
        'content_project.approval.list',
    ];

    public function __construct(
        private readonly ContentProjectAgentPlanApplicationService $application,
        private readonly ContentProjectAutomationPolicyService $policies,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(AgentExecutionContext $context, string $tool, array $input = []): AgentCapabilityResult
    {
        $tool = $this->canonicalize($tool);

        $result = match ($tool) {
            'content_project.plan' => $this->application->createDraft(
                $context,
                (string) ($input['objective'] ?? ''),
                is_array($input['constraints'] ?? null) ? $input['constraints'] : [],
            ),
            'content_project.confirm_plan' => $this->application->confirmPlan($context, (string) ($input['plan_ref'] ?? '')),
            'content_project.start_plan' => $this->application->startPlan($context, (string) ($input['plan_ref'] ?? '')),
            'content_project.pause_plan' => $this->application->pausePlan($context, (string) ($input['plan_ref'] ?? '')),
            'content_project.resume_plan' => $this->application->resumePlan($context, (string) ($input['plan_ref'] ?? '')),
            'content_project.cancel_plan' => $this->application->cancelPlan($context, (string) ($input['plan_ref'] ?? '')),
            'content_project.get_agent_plan' => $this->application->getPlan($context, (string) ($input['plan_ref'] ?? '')),
            'content_project.list_agent_plans' => $this->application->listPlans($context, (int) ($input['limit'] ?? 20)),
            'content_project.retry_plan_step' => $this->application->retryStep(
                $context,
                (string) ($input['plan_ref'] ?? ''),
                (string) ($input['step_ref'] ?? ''),
            ),
            'content_project.get_agent_policy' => $this->policies->previewForContext($context),
            'content_project.approve_agent_action' => $this->application->approve(
                $context,
                (string) ($input['approval_ref'] ?? ''),
                (string) ($input['state_fingerprint'] ?? ''),
            ),
            'content_project.reject_agent_action' => $this->application->rejectApproval(
                $context,
                (string) ($input['approval_ref'] ?? ''),
            ),
            'content_project.list_pending_approvals' => $this->application->listApprovals($context),
            default => [
                'success' => false,
                'code' => AgentErrorCodes::CAPABILITY_NOT_FOUND,
                'message' => 'Unknown plan tool.',
            ],
        };

        if (($result['success'] ?? false) === true) {
            return AgentCapabilityResult::ok(
                (string) $result['code'],
                (string) $result['message'],
                is_array($result['data'] ?? null) ? $result['data'] : [],
            );
        }

        return AgentCapabilityResult::fail(
            (string) ($result['code'] ?? AgentErrorCodes::INTERNAL_ERROR),
            (string) ($result['message'] ?? 'Plan tool failed.'),
            is_array($result['data'] ?? null) ? $result['data'] : [],
        );
    }

    private function canonicalize(string $tool): string
    {
        return match ($tool) {
            'content_project.plan.create_draft' => 'content_project.plan',
            'content_project.plan.confirm' => 'content_project.confirm_plan',
            'content_project.plan.start' => 'content_project.start_plan',
            'content_project.plan.pause' => 'content_project.pause_plan',
            'content_project.plan.resume' => 'content_project.resume_plan',
            'content_project.plan.cancel' => 'content_project.cancel_plan',
            'content_project.plan.get' => 'content_project.get_agent_plan',
            'content_project.plan.list' => 'content_project.list_agent_plans',
            'content_project.plan.retry_step' => 'content_project.retry_plan_step',
            'content_project.approval.approve' => 'content_project.approve_agent_action',
            'content_project.approval.reject' => 'content_project.reject_agent_action',
            'content_project.approval.list' => 'content_project.list_pending_approvals',
            default => $tool,
        };
    }
}
