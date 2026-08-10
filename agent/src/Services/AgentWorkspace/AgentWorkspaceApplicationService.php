<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentConversation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentSkillDefinition;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentExecutionOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionCancellation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionConfirmation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionRetry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentPlanningOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentProposedPlan;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeQuery;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\AgentMemoryCandidateExtractor;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationControlRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationDefinitionRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationRunRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentFeedbackService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentGovernancePolicyService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentMetricAggregator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentOperationsDashboardService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentReviewService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentTraceService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentAutomationHealthEvaluator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentEvaluationRunner;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentEvaluationRun;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentReview;
use RuntimeException;

/**
 * Application facade for Agent Workspace UI.
 * Phase 2: preview/execute/confirm/cancel/retry → AgentExecutionOrchestrator → AgentGateway.
 * Phase 3: natural-language planning → AgentPlanningOrchestrator (propose only, never execute).
 * Phase 4: knowledge/memory → AgentKnowledgeOrchestrator (no business table writes).
 * Phase 5: automations → AgentAutomationOrchestrator (scheduler/job never hit CommandBus).
 * Phase 6: observability → traces/metrics/reviews/evals (side-channel, no business mutation).
 * Phase 7: packs → AgentPackOrchestrator (declarative only, no CommandBus).
 * Does not call ContentProjectCommandBus or business services directly.
 */
final class AgentWorkspaceApplicationService
{
    /** @var list<string> */
    private const KNOWLEDGE_CAPABILITIES = [
        'agent.knowledge.list',
        'agent.knowledge.add',
        'agent.knowledge.search',
        'agent.knowledge.review_memory',
        'agent.knowledge.forget',
        'agent.knowledge.verify',
    ];

    /** @var list<string> */
    private const AUTOMATION_CAPABILITIES = [
        'agent.automation.list',
        'agent.automation.create',
        'agent.automation.status',
        'agent.automation.run',
        'agent.automation.pause',
        'agent.automation.resume',
        'agent.automation.delete',
        'agent.automation.history',
    ];

    /** @var list<string> */
    private const OBSERVABILITY_CAPABILITIES = [
        'agent.observability.health',
        'agent.observability.metrics',
        'agent.observability.trace',
        'agent.observability.review',
        'agent.observability.run_evaluation',
        'agent.observability.evaluation_status',
        'agent.observability.policy_violations',
        'agent.observability.automation_health',
    ];

    /** @var list<string> */
    private const PACK_CAPABILITIES = [
        'agent.pack.list',
        'agent.pack.status',
        'agent.pack.validate',
        'agent.pack.evaluate',
        'agent.pack.enable',
        'agent.pack.disable',
        'agent.pack.skills',
    ];

    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AgentSkillAvailabilityService $availability,
        private readonly AgentConversationService $conversations,
        private readonly AgentSkillInputResolver $inputResolver,
        private readonly AgentErrorPresentation $errors,
        private readonly AgentExecutionOrchestrator $orchestrator,
        private readonly ?AgentPlanningOrchestrator $planner = null,
        private readonly ?AgentKnowledgeOrchestrator $knowledge = null,
        private readonly ?AgentMemoryCandidateExtractor $memoryCandidates = null,
        private readonly ?AgentAutomationOrchestrator $automations = null,
        private readonly ?AgentOperationsDashboardService $operations = null,
        private readonly ?AgentTraceService $traces = null,
        private readonly ?AgentMetricAggregator $metricAggregates = null,
        private readonly ?AgentReviewService $reviews = null,
        private readonly ?AgentFeedbackService $feedback = null,
        private readonly ?AgentEvaluationRunner $evaluationRunner = null,
        private readonly ?AgentGovernancePolicyService $governance = null,
        private readonly ?AgentAutomationHealthEvaluator $automationHealth = null,
        private readonly ?\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackOrchestrator $packs = null,
    ) {}

    /**
     * Copilot planning — AI proposes only; never executes Gateway/CommandBus.
     *
     * @param  array<string, mixed>  $clarificationAnswers
     * @return array<string, mixed>
     */
    public function planNaturalLanguage(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        string $userMessage,
        array $clarificationAnswers = [],
        ?string $preferredModel = null,
    ): array {
        if ($this->planner === null) {
            return [
                'ok' => false,
                'code' => 'planning_unavailable',
                'message' => 'AI planning chưa sẵn sàng. Dùng slash skill (/).',
                'executed' => false,
            ];
        }

        return $this->planner->plan(new AgentPlanningRequest(
            context: $context,
            conversation: $conversation,
            userMessage: $userMessage,
            clarificationAnswers: $clarificationAnswers,
            preferredModel: $preferredModel,
        ));
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function answerClarification(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        string $userMessage,
        array $answers,
    ): array {
        if ($this->planner === null) {
            return ['ok' => false, 'code' => 'planning_unavailable', 'executed' => false];
        }

        return $this->planner->answerClarification(
            new AgentPlanningRequest(
                context: $context,
                conversation: $conversation,
                userMessage: $userMessage,
            ),
            $answers,
        );
    }

    /**
     * @param  array<string, mixed>  $planPayload
     * @param  array<string, mixed>  $edits
     * @return array<string, mixed>
     */
    public function editProposedPlan(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        array $planPayload,
        array $edits,
    ): array {
        if ($this->planner === null) {
            return ['ok' => false, 'code' => 'planning_unavailable'];
        }

        return $this->planner->editPlan(
            new AgentPlanningRequest(
                context: $context,
                conversation: $conversation,
                userMessage: '',
            ),
            AgentProposedPlan::fromArray($planPayload),
            $edits,
        );
    }

    /**
     * Save plan records only — does not run any step.
     *
     * @param  array<string, mixed>  $planPayload
     * @return array<string, mixed>
     */
    public function saveProposedPlan(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        array $planPayload,
    ): array {
        if ($this->planner === null) {
            return ['ok' => false, 'code' => 'planning_unavailable', 'executed' => false];
        }

        return $this->planner->savePlan(
            new AgentPlanningRequest(
                context: $context,
                conversation: $conversation,
                userMessage: '',
            ),
            AgentProposedPlan::fromArray($planPayload),
        );
    }

    /**
     * Open skill form — does not execute.
     *
     * @param  array<string, mixed>  $prefill
     * @return array{skill: array<string, mixed>, form: list<array<string, mixed>>, prefill: array<string, mixed>, availability: array{status: string, reason: string, usable: bool}}
     */
    public function openSkill(AgentWorkspaceContext $context, string $skillKey, array $prefill = []): array
    {
        $skill = $this->requireSkill($skillKey);
        $availability = $this->availability->resolve($skill, $context->toAvailabilityContext());

        return [
            'skill' => $skill->toArray(),
            'form' => $skill->formSchema,
            'prefill' => $this->inputResolver->prefill($skill, $context, $prefill),
            'availability' => $availability->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $formInput
     * @return array<string, mixed>
     */
    public function preview(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        string $skillKey,
        array $formInput,
    ): array {
        $skill = $this->requireSkill($skillKey);
        if (in_array($skill->capability, ['agent.help', 'agent.new_chat'], true)) {
            return $this->handleMeta($skill, $conversation, $context, preview: true);
        }
        if (in_array($skill->capability, self::KNOWLEDGE_CAPABILITIES, true)) {
            return $this->handleKnowledge($skill, $conversation, $context, $formInput, preview: true);
        }
        if (in_array($skill->capability, self::AUTOMATION_CAPABILITIES, true)) {
            return $this->handleAutomation($skill, $conversation, $context, $formInput, preview: true);
        }
        if (in_array($skill->capability, self::OBSERVABILITY_CAPABILITIES, true)) {
            return $this->handleObservability($skill, $context, $formInput, preview: true);
        }
        if (in_array($skill->capability, self::PACK_CAPABILITIES, true)) {
            return $this->handlePacks($skill, $context, $formInput, preview: true);
        }

        $preview = $this->orchestrator->preview(new AgentExecutionRequest(
            context: $context,
            conversation: $conversation,
            skillKey: $skill->key,
            formInput: $formInput,
            mode: 'preview',
        ));

        return array_merge($preview->toArray(), [
            'ok' => $preview->executable,
            'code' => $preview->executable ? 'preview_ok' : 'preview_blocked',
            'message' => $preview->warnings[0] ?? ($preview->executable ? 'Preview sẵn sàng.' : 'Preview không executable.'),
            'requires_confirmation' => $preview->requiresConfirmation,
            'input_summary' => $this->inputResolver->summarize($preview->normalizedInput),
        ]);
    }

    /**
     * @param  array<string, mixed>  $formInput
     * @return array<string, mixed>
     */
    public function execute(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        string $skillKey,
        array $formInput,
        ?string $confirmationToken = null,
        ?string $idempotencyKey = null,
    ): array {
        unset($idempotencyKey); // Browser must not set idempotency — orchestrator generates.

        $skill = $this->requireSkill($skillKey);
        if (in_array($skill->capability, ['agent.help', 'agent.new_chat'], true)) {
            return $this->handleMeta($skill, $conversation, $context, preview: false);
        }
        if (in_array($skill->capability, self::KNOWLEDGE_CAPABILITIES, true)) {
            return $this->handleKnowledge($skill, $conversation, $context, $formInput, preview: false);
        }
        if (in_array($skill->capability, self::AUTOMATION_CAPABILITIES, true)) {
            return $this->handleAutomation($skill, $conversation, $context, $formInput, preview: false);
        }
        if (in_array($skill->capability, self::OBSERVABILITY_CAPABILITIES, true)) {
            return $this->handleObservability($skill, $context, $formInput, preview: false);
        }
        if (in_array($skill->capability, self::PACK_CAPABILITIES, true)) {
            return $this->handlePacks($skill, $context, $formInput, preview: false);
        }

        if ($confirmationToken !== null && $confirmationToken !== '') {
            // Confirm path must carry execution_ref via formInput['_execution_ref'] from UI.
            $executionRef = isset($formInput['_execution_ref']) && is_string($formInput['_execution_ref'])
                ? $formInput['_execution_ref']
                : '';
            if ($executionRef === '') {
                return [
                    'ok' => false,
                    'code' => 'confirmation_required',
                    'message' => 'Thiếu execution_ref khi confirm.',
                    'requires_confirmation' => true,
                ];
            }

            $result = $this->orchestrator->confirm(new AgentExecutionConfirmation(
                context: $context,
                executionRef: $executionRef,
                confirmationToken: $confirmationToken,
            ));

            return $result->toArray();
        }

        $result = $this->orchestrator->execute(new AgentExecutionRequest(
            context: $context,
            conversation: $conversation,
            skillKey: $skill->key,
            formInput: $formInput,
            mode: 'execute',
        ));

        return $result->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmExecution(
        AgentWorkspaceContext $context,
        string $executionRef,
        string $confirmationToken,
    ): array {
        return $this->orchestrator->confirm(new AgentExecutionConfirmation(
            context: $context,
            executionRef: $executionRef,
            confirmationToken: $confirmationToken,
        ))->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelExecution(
        AgentWorkspaceContext $context,
        string $executionRef,
        ?string $reason = null,
    ): array {
        return $this->orchestrator->cancel(new AgentExecutionCancellation(
            context: $context,
            executionRef: $executionRef,
            reason: $reason,
        ))->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function retryExecution(
        AgentWorkspaceContext $context,
        string $executionRef,
    ): array {
        return $this->orchestrator->retry(new AgentExecutionRetry(
            context: $context,
            executionRef: $executionRef,
        ))->toArray();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function listKnowledge(AgentWorkspaceContext $context, array $filters = []): array
    {
        if ($this->knowledge === null) {
            return [];
        }

        return $this->knowledge->list($context, $filters);
    }

    /**
     * @return array<string, mixed>
     */
    public function forgetKnowledge(AgentWorkspaceContext $context, string $hashId): array
    {
        if ($this->knowledge === null) {
            return ['ok' => false, 'code' => 'knowledge_unavailable'];
        }

        return $this->knowledge->forget($context, $hashId);
    }

    /**
     * @param  array<string, mixed>  $edits
     * @return array<string, mixed>
     */
    public function resolveMemoryProposal(
        AgentWorkspaceContext $context,
        string $proposalId,
        string $action,
        array $edits = [],
    ): array {
        if ($this->knowledge === null) {
            return ['ok' => false, 'code' => 'knowledge_unavailable'];
        }

        return $this->knowledge->resolveProposal($context, $proposalId, $action, $edits);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function proposeMemory(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        array $candidate,
    ): array {
        if ($this->knowledge === null) {
            return ['ok' => false, 'code' => 'knowledge_unavailable'];
        }
        $proposal = $this->knowledge->createProposal($context, $conversation, $candidate);

        return ['ok' => true, 'proposal' => $proposal->toArray()];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function extractMemoryCandidates(AgentWorkspaceContext $context, string $message): array
    {
        $extractor = $this->memoryCandidates ?? new AgentMemoryCandidateExtractor;

        return array_map(
            static fn ($c): array => $c->toArray(),
            $extractor->extract($message, $context),
        );
    }

    /**
     * @param  array<string, mixed>  $formInput
     * @return array<string, mixed>
     */
    private function handleKnowledge(
        AgentSkillDefinition $skill,
        SeoAgentConversation $conversation,
        AgentWorkspaceContext $context,
        array $formInput,
        bool $preview,
    ): array {
        if ($this->knowledge === null) {
            return ['ok' => false, 'code' => 'knowledge_unavailable', 'message' => 'Knowledge chưa sẵn sàng.'];
        }

        if ($preview) {
            return [
                'ok' => true,
                'code' => 'preview_ok',
                'message' => 'Preview knowledge action.',
                'requires_confirmation' => in_array($skill->confirmationPolicy, ['preview', 'confirm', 'destructive'], true),
                'input_summary' => $this->inputResolver->summarize($formInput),
                'executable' => true,
            ];
        }

        return match ($skill->capability) {
            'agent.knowledge.list' => [
                'ok' => true,
                'code' => 'ok',
                'message' => 'Danh sách knowledge.',
                'items' => $this->knowledge->list($context),
            ],
            'agent.knowledge.add' => $this->knowledge->ingest($context, [
                'source_type' => 'manual',
                'title' => $formInput['title'] ?? '',
                'content' => $formInput['content'] ?? '',
                'type' => $formInput['type'] ?? 'general_note',
                'scope_type' => $formInput['scope_type'] ?? 'site',
                'trust_level' => 'user_confirmed',
                'status' => 'active',
            ]),
            'agent.knowledge.search' => $this->knowledge->search($context, new AgentKnowledgeQuery(
                tenantId: $context->tenantId,
                siteId: $context->siteId,
                connectionHash: null,
                message: (string) ($formInput['query'] ?? ''),
                siteRef: $context->siteRef,
                projectRef: $context->projectRef,
                workspaceRef: $context->workspaceRef,
                ownerUserId: $context->actorUserId,
            )),
            'agent.knowledge.verify' => $this->knowledge->verify(
                $context,
                (string) ($formInput['knowledge_ref'] ?? ''),
            ),
            'agent.knowledge.forget' => $this->knowledge->forget(
                $context,
                (string) ($formInput['knowledge_ref'] ?? ''),
            ),
            'agent.knowledge.review_memory' => [
                'ok' => true,
                'code' => 'ok',
                'message' => 'Mở Knowledge tab để duyệt memory proposals.',
                'action' => 'open_knowledge_panel',
            ],
            default => ['ok' => false, 'code' => 'unknown_knowledge_skill'],
        };
    }

    /**
     * @param  array<string, mixed>  $formInput
     * @return array<string, mixed>
     */
    private function handleAutomation(
        AgentSkillDefinition $skill,
        SeoAgentConversation $conversation,
        AgentWorkspaceContext $context,
        array $formInput,
        bool $preview,
    ): array {
        if ($this->automations === null) {
            return ['ok' => false, 'code' => 'automation_unavailable', 'message' => 'Automations chưa sẵn sàng.'];
        }

        if ($preview) {
            if ($skill->capability === 'agent.automation.create') {
                $req = $this->automationDefinitionFromForm($formInput, $context);
                $previewCard = $this->automations->previewDefinition($context, $req);

                return [
                    'ok' => true,
                    'code' => 'preview_ok',
                    'message' => 'Preview automation — cần save tường minh.',
                    'requires_confirmation' => true,
                    'card' => 'automation_proposal',
                    'preview' => $previewCard->toArray(),
                    'executable' => true,
                ];
            }

            return [
                'ok' => true,
                'code' => 'preview_ok',
                'message' => 'Preview automation action.',
                'requires_confirmation' => in_array($skill->confirmationPolicy, ['preview', 'confirm', 'destructive'], true),
                'input_summary' => $this->inputResolver->summarize($formInput),
                'executable' => true,
            ];
        }

        return match ($skill->capability) {
            'agent.automation.list' => [
                'ok' => true,
                'code' => 'ok',
                'message' => 'Danh sách automations.',
                'card' => 'automation_list',
                'items' => $this->automations->list($context),
                'action' => 'open_automations_panel',
            ],
            'agent.automation.create' => $this->automations->create(
                $context,
                $this->automationDefinitionFromForm($formInput, $context),
                explicitSave: ((string) ($formInput['explicit_save'] ?? '0')) === '1',
            )->toArray(),
            'agent.automation.status' => [
                'ok' => true,
                'code' => 'ok',
                'automation' => $this->automations->get($context, (string) ($formInput['automation_ref'] ?? '')),
            ],
            'agent.automation.run' => $this->automations->runNow(
                $context,
                new AgentAutomationRunRequest((string) ($formInput['automation_ref'] ?? ''), 'manual'),
            )->toArray(),
            'agent.automation.pause' => $this->automations->control(
                $context,
                new AgentAutomationControlRequest(
                    (string) ($formInput['automation_ref'] ?? ''),
                    AgentAutomationControlRequest::ACTION_PAUSE,
                ),
            )->toArray(),
            'agent.automation.resume' => $this->automations->control(
                $context,
                new AgentAutomationControlRequest(
                    (string) ($formInput['automation_ref'] ?? ''),
                    AgentAutomationControlRequest::ACTION_RESUME,
                ),
            )->toArray(),
            'agent.automation.delete' => $this->automations->control(
                $context,
                new AgentAutomationControlRequest(
                    (string) ($formInput['automation_ref'] ?? ''),
                    AgentAutomationControlRequest::ACTION_DELETE,
                ),
            )->toArray(),
            'agent.automation.history' => [
                'ok' => true,
                'code' => 'ok',
                'card' => 'automation_history',
                'runs' => $this->automations->history(
                    $context,
                    (string) ($formInput['automation_ref'] ?? ''),
                ),
            ],
            default => ['ok' => false, 'code' => 'unknown_automation_skill'],
        };
    }

    /**
     * @param  array<string, mixed>  $formInput
     */
    private function automationDefinitionFromForm(array $formInput, AgentWorkspaceContext $context): AgentAutomationDefinitionRequest
    {
        $type = (string) ($formInput['type'] ?? 'scheduled_report');
        $skillKey = trim((string) ($formInput['skill_key'] ?? 'operations.site_health'));
        $workflow = match ($type) {
            'planning_workflow' => [
                ['type' => 'planning', 'prompt' => (string) ($formInput['prompt'] ?? 'Đề xuất kế hoạch SEO tuần này')],
                ['type' => 'notification'],
            ],
            'guarded_action' => [
                ['type' => 'execution_preview', 'skill_key' => $skillKey !== '' ? $skillKey : 'content_project.schedule', 'input' => []],
                ['type' => 'notification'],
            ],
            'condition_watch' => [
                ['type' => 'read_skill', 'skill_key' => $skillKey !== '' ? $skillKey : 'operations.site_health', 'input' => []],
                [
                    'type' => 'condition',
                    'condition' => [
                        'mode' => 'all',
                        'rules' => [
                            ['path' => 'status', 'operator' => 'changed', 'value' => null],
                        ],
                    ],
                ],
                ['type' => 'notification'],
            ],
            default => [
                ['type' => 'read_skill', 'skill_key' => $skillKey !== '' ? $skillKey : 'operations.site_health', 'input' => []],
                ['type' => 'notification'],
            ],
        };

        return AgentAutomationDefinitionRequest::fromArray([
            'name' => (string) ($formInput['name'] ?? 'Automation'),
            'description' => $formInput['description'] ?? null,
            'type' => $type,
            'timezone' => (string) ($formInput['timezone'] ?? 'UTC'),
            'trigger' => [
                'frequency' => (string) ($formInput['frequency'] ?? 'daily'),
                'time' => (string) ($formInput['time'] ?? '09:00'),
                'timezone' => (string) ($formInput['timezone'] ?? 'UTC'),
                'days_of_week' => $formInput['days_of_week'] ?? [1],
                'day_of_month' => (int) ($formInput['day_of_month'] ?? 1),
                'interval_minutes' => (int) ($formInput['interval_minutes'] ?? 60),
            ],
            'workflow' => $workflow,
            'notification' => [
                'policy' => (string) ($formInput['notification_policy'] ?? 'always'),
                'destinations' => ['agent_workspace'],
            ],
            'policy' => [
                'auto_execute_safe_writes' => false,
                'require_confirmation' => true,
            ],
            'enabled' => true,
            'scope_type' => 'site',
            'scope_ref' => $context->siteRef,
            'conversation_id' => $formInput['conversation_id'] ?? null,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAutomations(AgentWorkspaceContext $context): array
    {
        return $this->automations?->list($context) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function automationDiagnostics(AgentWorkspaceContext $context, string $automationHashId): array
    {
        return $this->automations?->diagnostics($context, $automationHashId) ?? ['ok' => false, 'code' => 'unavailable'];
    }

    /**
     * @param  array<string, mixed>  $formInput
     * @return array<string, mixed>
     */
    private function handleObservability(
        AgentSkillDefinition $skill,
        AgentWorkspaceContext $context,
        array $formInput,
        bool $preview,
    ): array {
        $gov = $this->governance ?? new AgentGovernancePolicyService;
        if (! $gov->canAccessDiagnostics($context->role, $context->scopes)) {
            return ['ok' => false, 'code' => 'forbidden', 'message' => 'Manager-only observability.'];
        }

        if ($preview) {
            return [
                'ok' => true,
                'code' => 'preview_ok',
                'message' => 'Preview observability action.',
                'requires_confirmation' => in_array($skill->confirmationPolicy, ['preview', 'confirm'], true),
                'executable' => true,
            ];
        }

        return match ($skill->capability) {
            'agent.observability.health' => $this->operations?->overview($context)
                ?? ['ok' => false, 'code' => 'unavailable'],
            'agent.observability.metrics' => [
                'ok' => true,
                'code' => 'ok',
                'rows' => $this->metricAggregates?->snapshot($context->siteId, 7) ?? [],
                'action' => 'open_operations_panel',
            ],
            'agent.observability.trace' => [
                'ok' => true,
                'code' => 'ok',
                'trace' => $this->traces?->getTraceTimeline(
                    (string) ($formInput['trace_id'] ?? ''),
                    $context->siteId,
                ),
            ],
            'agent.observability.review' => [
                'ok' => true,
                'code' => 'ok',
                'items' => $this->reviews?->listOpen($context->siteId) ?? [],
                'action' => 'open_operations_panel',
            ],
            'agent.observability.run_evaluation' => $this->evaluationRunner?->run(
                datasetKey: (string) ($formInput['dataset'] ?? 'core-routing'),
                dryRun: ((string) ($formInput['dry_run'] ?? '1')) === '1',
                createdBy: $context->actorUserId,
            ) ?? ['ok' => false, 'code' => 'unavailable'],
            'agent.observability.evaluation_status' => [
                'ok' => true,
                'code' => 'ok',
                'runs' => SeoAgentEvaluationRun::query()
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get()
                    ->map(static fn (SeoAgentEvaluationRun $r): array => [
                        'hash_id' => $r->hash_id,
                        'status' => $r->status,
                        'gate_status' => $r->gate_status,
                        'summary' => $r->summary,
                    ])->all(),
            ],
            'agent.observability.policy_violations' => [
                'ok' => true,
                'code' => 'ok',
                'items' => SeoAgentReview::query()
                    ->where('site_id', $context->siteId)
                    ->where('reason', 'policy_violation')
                    ->orderByDesc('id')
                    ->limit(50)
                    ->get()
                    ->map(static fn (SeoAgentReview $r): array => [
                        'hash_id' => $r->hash_id,
                        'severity' => $r->severity,
                        'status' => $r->status,
                        'payload' => $r->payload,
                        'trace_id' => $r->trace_id,
                    ])->all(),
            ],
            'agent.observability.automation_health' => [
                'ok' => true,
                'code' => 'ok',
                'health' => ($this->automationHealth ?? new AgentAutomationHealthEvaluator)->evaluate([
                    'failure_streak' => (int) ($formInput['failure_streak'] ?? 0),
                    'no_change_streak' => (int) ($formInput['no_change_streak'] ?? 0),
                    'notification_spam' => (int) ($formInput['notification_spam'] ?? 0),
                    'permission_loss' => (int) ($formInput['permission_loss'] ?? 0),
                ]),
                'auto_pause' => false,
            ],
            default => ['ok' => false, 'code' => 'unknown_observability_skill'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function operationsOverview(AgentWorkspaceContext $context): array
    {
        return $this->operations?->overview($context) ?? ['ok' => false, 'code' => 'unavailable'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPacks(AgentWorkspaceContext $context): array
    {
        $gov = $this->governance ?? new AgentGovernancePolicyService;
        if (! $gov->canAccessDiagnostics($context->role, $context->scopes)) {
            return [];
        }

        return $this->packs?->listPacks() ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function skillGroups(): array
    {
        return (new \Omnichannel\Addons\Agent\Services\AgentWorkspace\V1\AgentSkillGroupCatalog)
            ->present($this->skills);
    }

    /**
     * @return array<string, mixed>
     */
    public function v1Readiness(AgentWorkspaceContext $context, bool $fixSafe = false): array
    {
        $gov = $this->governance ?? new AgentGovernancePolicyService;
        if (! $gov->canAccessDiagnostics($context->role, $context->scopes)) {
            return ['ok' => false, 'code' => 'forbidden', 'overall' => 'not_ready'];
        }

        return app(\Omnichannel\Addons\Agent\Services\AgentWorkspace\V1\AgentV1ReadinessService::class)
            ->run(fixSafe: $fixSafe, skipProvider: true);
    }

    /**
     * @return array<string, string>
     */
    public function workspaceVersion(): array
    {
        return \Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceVersion::snapshot();
    }

    /**
     * @param  array<string, mixed>  $formInput
     * @return array<string, mixed>
     */
    private function handlePacks(
        AgentSkillDefinition $skill,
        AgentWorkspaceContext $context,
        array $formInput,
        bool $preview,
    ): array {
        $gov = $this->governance ?? new AgentGovernancePolicyService;
        if (! $gov->canAccessDiagnostics($context->role, $context->scopes)) {
            return ['ok' => false, 'code' => 'forbidden', 'message' => 'Manager-only packs.'];
        }
        if ($this->packs === null) {
            return ['ok' => false, 'code' => 'packs_unavailable'];
        }

        if ($preview) {
            return [
                'ok' => true,
                'code' => 'preview_ok',
                'message' => 'Preview pack action.',
                'requires_confirmation' => in_array($skill->confirmationPolicy, ['preview', 'confirm'], true),
                'executable' => true,
                'capability_executed' => false,
            ];
        }

        return match ($skill->capability) {
            'agent.pack.list' => [
                'ok' => true,
                'code' => 'ok',
                'items' => $this->packs->listPacks(),
                'action' => 'open_packs_panel',
            ],
            'agent.pack.status' => $this->packStatus((string) ($formInput['pack_ref'] ?? '')),
            'agent.pack.validate' => $this->packValidate((string) ($formInput['manifest_json'] ?? '')),
            'agent.pack.evaluate' => $this->evaluationRunner?->run(
                datasetKey: 'pack:'.((string) ($formInput['pack_key'] ?? '')).':'.((string) ($formInput['dataset_key'] ?? '')),
                dryRun: ((string) ($formInput['dry_run'] ?? '1')) === '1',
                createdBy: $context->actorUserId,
            ) ?? ['ok' => false, 'code' => 'unavailable'],
            'agent.pack.enable' => $this->packs->enable(
                (string) ($formInput['pack_ref'] ?? ''),
                $context->actorUserId,
                ((string) ($formInput['explicit_approval'] ?? '')) === '1',
            ),
            'agent.pack.disable' => $this->packs->disable(
                (string) ($formInput['pack_ref'] ?? ''),
                $context->actorUserId,
            ),
            'agent.pack.skills' => $this->packSkills((string) ($formInput['pack_ref'] ?? '')),
            default => ['ok' => false, 'code' => 'unknown_pack_skill'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function packStatus(string $ref): array
    {
        $items = $this->packs?->listPacks() ?? [];
        foreach ($items as $item) {
            if (($item['hash_id'] ?? '') === $ref || ($item['key'] ?? '') === $ref) {
                return ['ok' => true, 'code' => 'ok', 'pack' => $item];
            }
        }

        return ['ok' => false, 'code' => 'not_found'];
    }

    /**
     * @return array<string, mixed>
     */
    private function packValidate(string $json): array
    {
        try {
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'code' => 'invalid_json'];
        }

        $result = $this->packs?->validateManifest($manifest) ?? ['ok' => false, 'errors' => ['unavailable']];

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'code' => ($result['ok'] ?? false) ? 'validated' : 'validation_failed',
            'errors' => $result['errors'] ?? [],
            'revision_hash' => $result['revision_hash'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function packSkills(string $ref): array
    {
        $status = $this->packStatus($ref);
        if (! ($status['ok'] ?? false)) {
            return $status;
        }
        $key = (string) (($status['pack']['key'] ?? ''));
        $skills = [];
        foreach ($this->skills->all(true) as $skill) {
            $arr = $skill->toArray();
            if (($arr['pack_key'] ?? null) === $key || str_starts_with($skill->key, $key.'.')) {
                $skills[] = $arr;
            }
        }

        return ['ok' => true, 'code' => 'ok', 'skills' => $skills];
    }

    /**
     * @return array<string, mixed>
     */
    public function submitFeedback(
        AgentWorkspaceContext $context,
        int $messageId,
        int $conversationId,
        bool $useful,
        ?string $reason = null,
        ?string $comment = null,
        ?string $traceId = null,
    ): array {
        return $this->feedback?->submit(
            $context,
            $messageId,
            $conversationId,
            $useful,
            $reason,
            $comment,
            $traceId,
        ) ?? ['ok' => false, 'code' => 'unavailable'];
    }

    private function requireSkill(string $skillKey): AgentSkillDefinition
    {
        $skill = $this->skills->get($skillKey) ?? $this->skills->resolveSlashCommand($skillKey);
        if ($skill === null) {
            throw new RuntimeException('agent.skill_not_found');
        }

        return $skill;
    }

    /**
     * @return array<string, mixed>
     */
    private function handleMeta(
        AgentSkillDefinition $skill,
        SeoAgentConversation $conversation,
        AgentWorkspaceContext $context,
        bool $preview,
    ): array {
        if ($skill->capability === 'agent.new_chat') {
            return [
                'ok' => true,
                'code' => 'ok',
                'message' => 'Tạo chat mới.',
                'action' => 'new_chat',
            ];
        }

        $groups = [
            'Content' => ['content_project.create', 'content_project.generate', 'content_project.start_review', 'content_project.schedule'],
            'Planning' => ['keyword.import', 'keyword.analyze', 'keyword.build_topical_map'],
            'Knowledge' => ['knowledge.list', 'knowledge.add', 'knowledge.search'],
            'Automations' => ['automation.list', 'automation.create', 'automation.run', 'automation.history'],
            'Monitoring' => ['operations.operation_status', 'content_project.publishing_queue', 'operations.site_health'],
        ];

        $cards = [];
        foreach ($groups as $group => $keys) {
            $items = [];
            foreach ($keys as $key) {
                $item = $this->skills->get($key);
                if ($item !== null) {
                    $items[] = ['skill_key' => $item->key, 'name' => $item->name, 'slash' => $item->slashCommand];
                }
            }
            $cards[] = ['group' => $group, 'items' => $items];
        }

        if (! $preview) {
            $this->conversations->appendMessage(
                $conversation,
                role: 'assistant',
                messageType: 'notice',
                content: 'Bạn muốn làm gì?',
                structured: ['help_groups' => $cards],
                skillKey: $skill->key,
                createdBy: $context->actorUserId,
            );
        }

        return [
            'ok' => true,
            'code' => 'ok',
            'message' => 'Bạn muốn làm gì?',
            'help_groups' => $cards,
        ];
    }
}
