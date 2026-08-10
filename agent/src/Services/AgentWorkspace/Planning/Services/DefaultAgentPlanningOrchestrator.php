<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillAvailabilityService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentPlanStepRunner;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentConversationSummarizer;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentModelGateway;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentModelRouter;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentPlanningOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentPlanValidator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentClarifyingQuestion;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentModelRoutingContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningResponse;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentProposedPlan;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentProposedPlanStep;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentSummarizationRequest;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DefaultAgentPlanningOrchestrator implements AgentPlanningOrchestrator
{
    public const HIGH_CONFIDENCE = 0.80;

    public const CLARIFICATION_THRESHOLD = 0.55;

    public function __construct(
        private readonly AgentModelRouter $router,
        private readonly AgentModelGateway $gateway,
        private readonly AgentPlanningContextAssembler $assembler,
        private readonly AgentPlanValidator $validator,
        private readonly DeterministicAgentPlanRepairer $repairer,
        private readonly AgentSkillRegistry $skills,
        private readonly AgentSkillAvailabilityService $availability,
        private readonly AgentPlanningPersistence $persistence,
        private readonly ?AgentConversationSummarizer $summarizer = null,
        private readonly ?AgentPlanStepRunner $planRunner = null,
        private readonly int $maxProviderCallsPerMessage = 1,
    ) {}

    public function plan(AgentPlanningRequest $request): array
    {
        $planningRequestId = $request->planningRequestId
            ?? ('aplanreq_'.Str::lower((string) Str::ulid()));

        $request = new AgentPlanningRequest(
            context: $request->context,
            conversation: $request->conversation,
            userMessage: $request->userMessage,
            taskType: $request->taskType,
            clarificationAnswers: $request->clarificationAnswers,
            hints: $request->hints,
            preferredModel: $request->preferredModel,
            planningRequestId: $planningRequestId,
        );

        $run = $this->persistence->startRun($request->conversation, [
            'planning_request_id' => $planningRequestId,
            'status' => 'running',
        ]);

        try {
            $routing = $this->router->resolve(new AgentModelRoutingContext(
                taskType: $request->taskType !== '' ? $request->taskType : 'plan_generation',
                requiresStructuredOutput: true,
                userSelectedModel: $request->preferredModel,
                connectionId: $request->context->connectionId,
                siteRef: $request->context->siteRef,
                allowFallback: true,
            ));
        } catch (Throwable $e) {
            $category = $this->errorCategory($e);
            $this->persistence->finishRun($run, [
                'status' => 'failed',
                'error_category' => $category,
            ]);

            return $this->failurePayload($category, $request);
        }

        $assembled = $this->assembler->assemble($request, $routing->contextLimitTokens);

        try {
            if ($this->maxProviderCallsPerMessage < 1) {
                throw new RuntimeException('model_unavailable');
            }
            $gatewayResult = $this->gateway->plan($request, $routing, $assembled);
        } catch (Throwable $e) {
            $category = $this->errorCategory($e);
            $this->persistence->finishRun($run, [
                'status' => 'failed',
                'provider' => $routing->providerKey,
                'model' => $routing->model,
                'routing_reason' => $routing->routingReason,
                'prompt_fingerprint' => $assembled['prompt_fingerprint'],
                'context_manifest' => $assembled['manifest'],
                'input_token_estimate' => $assembled['budget']['input_token_estimate'] ?? null,
                'error_category' => $category,
                'latency_ms' => null,
            ]);

            return $this->failurePayload($category, $request);
        }

        /** @var AgentPlanningResponse $response */
        $response = $gatewayResult['response'];
        $repairActions = is_array($gatewayResult['meta']['repair_actions'] ?? null)
            ? $gatewayResult['meta']['repair_actions']
            : [];

        $validation = $this->validator->validate($response, $request, $request->context);
        if (! $validation->ok) {
            // Deterministic repair once more on toArray then re-validate.
            $repaired = $this->repairer->repair($response->toArray());
            $repairActions = array_values(array_unique(array_merge($repairActions, $repaired['repair_actions'])));
            $validation = $this->validator->validate($repaired['response'], $request, $request->context);
            $response = $repaired['response'];
        }

        if (! $validation->ok) {
            $fallback = $this->invalidToClarificationOrUnsupported($validation->errors, $request);
            $this->persistence->finishRun($run, [
                'status' => 'completed',
                'response_type' => $fallback->type,
                'provider' => $routing->providerKey,
                'model' => $gatewayResult['meta']['model'] ?? $routing->model,
                'routing_reason' => $routing->routingReason.($routing->fallbackUsed ? '|fallback' : ''),
                'prompt_fingerprint' => $assembled['prompt_fingerprint'],
                'context_manifest' => $assembled['manifest'],
                'input_token_estimate' => $assembled['budget']['input_token_estimate'] ?? null,
                'confidence' => $response->confidence,
                'adjusted_confidence' => $validation->adjustedConfidence,
                'structured_response' => $this->persistence->redactResponse($fallback),
                'validation_errors' => $validation->errors,
                'repair_actions' => $repairActions,
                'latency_ms' => $gatewayResult['meta']['latency_ms'] ?? null,
                'error_category' => 'validation_failed',
            ]);

            return $this->successPayload($fallback, $request, [
                'planning_request_id' => $planningRequestId,
                'routing' => $routing->toArray(),
                'diagnostics' => $this->persistence->diagnosticsPayload($run),
                'uncertain' => true,
                'validation_errors' => $validation->errors,
            ]);
        }

        $adjusted = $this->adjustConfidence($validation->adjustedConfidence, $response, $request);
        $final = $this->applyConfidencePolicy($response, $adjusted, $request);

        $this->persistence->finishRun($run, [
            'status' => 'completed',
            'response_type' => $final->type,
            'provider' => $routing->providerKey,
            'model' => $gatewayResult['meta']['model'] ?? $routing->model,
            'routing_reason' => $routing->routingReason.($routing->fallbackUsed ? '|fallback' : ''),
            'prompt_fingerprint' => $assembled['prompt_fingerprint'],
            'context_manifest' => $assembled['manifest'],
            'input_token_estimate' => $assembled['budget']['input_token_estimate'] ?? null,
            'confidence' => $response->confidence,
            'adjusted_confidence' => $adjusted,
            'structured_response' => $this->persistence->redactResponse($final),
            'validation_errors' => [],
            'repair_actions' => $repairActions,
            'latency_ms' => $gatewayResult['meta']['latency_ms'] ?? null,
        ]);

        $this->maybeSummarize($request);

        return $this->successPayload($final, $request, [
            'planning_request_id' => $planningRequestId,
            'routing' => $routing->toArray(),
            'diagnostics' => $this->persistence->diagnosticsPayload($run),
            'uncertain' => $adjusted >= self::CLARIFICATION_THRESHOLD && $adjusted < self::HIGH_CONFIDENCE,
            'repair_actions' => $repairActions,
        ]);
    }

    public function answerClarification(AgentPlanningRequest $request, array $answers): array
    {
        $safe = [];
        foreach ($answers as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $this->plan(new AgentPlanningRequest(
            context: $request->context,
            conversation: $request->conversation,
            userMessage: $request->userMessage,
            taskType: 'plan_generation',
            clarificationAnswers: $safe,
            hints: $request->hints,
            preferredModel: $request->preferredModel,
        ));
    }

    public function validateProposal(AgentPlanningResponse $response, AgentPlanningRequest $request): AgentPlanningResponse
    {
        $repaired = $this->repairer->repair($response->toArray());
        $validation = $this->validator->validate($repaired['response'], $request, $request->context);
        if (! $validation->ok || $validation->response === null) {
            return $this->invalidToClarificationOrUnsupported($validation->errors, $request);
        }
        $adjusted = $this->adjustConfidence($validation->adjustedConfidence, $validation->response, $request);

        return $this->applyConfidencePolicy($validation->response, $adjusted, $request);
    }

    public function editPlan(AgentPlanningRequest $request, AgentProposedPlan $plan, array $edits): array
    {
        $steps = $plan->steps;

        if (isset($edits['remove_index']) && is_numeric($edits['remove_index'])) {
            $remove = (int) $edits['remove_index'];
            $steps = array_values(array_filter(
                $steps,
                static fn (AgentProposedPlanStep $s): bool => $s->index !== $remove,
            ));
            $steps = $this->reindexSteps($steps);
        }

        if (isset($edits['reorder']) && is_array($edits['reorder'])) {
            $byIndex = [];
            foreach ($steps as $step) {
                $byIndex[$step->index] = $step;
            }
            $reordered = [];
            $i = 1;
            foreach ($edits['reorder'] as $idx) {
                $idx = (int) $idx;
                if (! isset($byIndex[$idx])) {
                    continue;
                }
                $old = $byIndex[$idx];
                $reordered[] = new AgentProposedPlanStep(
                    index: $i,
                    skillKey: $old->skillKey,
                    input: $old->input,
                    dependsOn: $i > 1 ? [$i - 1] : [],
                    outputBindings: $old->outputBindings,
                    title: $old->title,
                );
                $i++;
            }
            if ($reordered !== []) {
                $steps = $reordered;
            }
        }

        if (isset($edits['set_skill']) && is_array($edits['set_skill'])) {
            $index = (int) ($edits['set_skill']['index'] ?? 0);
            $skillKey = (string) ($edits['set_skill']['skill_key'] ?? '');
            $skill = $this->skills->get($skillKey);
            if ($skill === null || $skill->isHidden) {
                return [
                    'ok' => false,
                    'code' => 'invalid_skill',
                    'message' => 'Skill không hợp lệ.',
                ];
            }
            $steps = array_map(
                static function (AgentProposedPlanStep $s) use ($index, $skill): AgentProposedPlanStep {
                    if ($s->index !== $index) {
                        return $s;
                    }

                    return new AgentProposedPlanStep(
                        index: $s->index,
                        skillKey: $skill->key,
                        input: $s->input,
                        dependsOn: $s->dependsOn,
                        outputBindings: $s->outputBindings,
                        title: $skill->name,
                    );
                },
                $steps,
            );
        }

        if (isset($edits['set_input']) && is_array($edits['set_input'])) {
            $index = (int) ($edits['set_input']['index'] ?? 0);
            $input = is_array($edits['set_input']['input'] ?? null) ? $edits['set_input']['input'] : [];
            $steps = array_map(
                static function (AgentProposedPlanStep $s) use ($index, $input): AgentProposedPlanStep {
                    if ($s->index !== $index) {
                        return $s;
                    }

                    return new AgentProposedPlanStep(
                        index: $s->index,
                        skillKey: $s->skillKey,
                        input: $input,
                        dependsOn: $s->dependsOn,
                        outputBindings: $s->outputBindings,
                        title: $s->title,
                    );
                },
                $steps,
            );
        }

        $proposed = new AgentPlanningResponse(
            type: AgentPlanningResponse::TYPE_EXECUTION_PLAN,
            confidence: 1.0,
            summary: 'Edited plan',
            plan: new AgentProposedPlan($steps),
            adjustedConfidence: 1.0,
        );
        $validated = $this->validateProposal($proposed, $request);

        return [
            'ok' => $validated->type === AgentPlanningResponse::TYPE_EXECUTION_PLAN,
            'code' => 'plan_edited',
            'message' => 'Plan đã được validate lại.',
            'response' => $validated->toArray(),
            'ui' => $this->uiCard($validated, $request),
        ];
    }

    public function savePlan(AgentPlanningRequest $request, AgentProposedPlan $plan): array
    {
        $proposed = new AgentPlanningResponse(
            type: AgentPlanningResponse::TYPE_EXECUTION_PLAN,
            confidence: 1.0,
            summary: 'Saved plan',
            plan: $plan,
            adjustedConfidence: 1.0,
        );
        $validated = $this->validateProposal($proposed, $request);
        if ($validated->type !== AgentPlanningResponse::TYPE_EXECUTION_PLAN || $validated->plan === null) {
            return [
                'ok' => false,
                'code' => 'plan_invalid',
                'message' => 'Plan không hợp lệ để lưu.',
                'response' => $validated->toArray(),
            ];
        }

        if ($this->planRunner === null) {
            return [
                'ok' => false,
                'code' => 'plan_runner_unavailable',
                'message' => 'Không lưu được plan (runner unavailable).',
            ];
        }

        $steps = [];
        foreach ($validated->plan->steps as $step) {
            $skill = $this->skills->get($step->skillKey);
            $steps[] = [
                'skill_key' => $step->skillKey,
                'title' => $step->title !== '' ? $step->title : ($skill?->name ?? $step->skillKey),
                'form_input' => $step->input,
            ];
        }

        $saved = $this->planRunner->createPlan($request->context, $request->conversation, $steps);

        return [
            'ok' => true,
            'code' => 'plan_saved',
            'message' => 'Đã lưu kế hoạch. Chưa chạy bước nào — duyệt từng bước.',
            'plan_ref' => $saved->public_ref,
            'executed' => false,
            'run_all' => false,
        ];
    }

    public function suggestNextActions(AgentPlanningRequest $request, array $resultContext = []): array
    {
        $keys = [];
        if (isset($resultContext['suggested_skills']) && is_array($resultContext['suggested_skills'])) {
            foreach ($resultContext['suggested_skills'] as $row) {
                if (is_string($row)) {
                    $keys[] = $row;
                } elseif (is_array($row) && isset($row['skill_key'])) {
                    $keys[] = (string) $row['skill_key'];
                }
            }
        }

        $out = [];
        foreach (array_slice(array_unique($keys), 0, 5) as $key) {
            $skill = $this->skills->get($key);
            if ($skill === null || $skill->isHidden) {
                continue;
            }
            $avail = $this->availability->resolve($skill, $request->context->toAvailabilityContext());
            if (! $avail->usable) {
                continue;
            }
            $out[] = [
                'skill_key' => $skill->key,
                'name' => $skill->name,
            ];
        }

        return $out;
    }

    private function adjustConfidence(
        float $base,
        AgentPlanningResponse $response,
        AgentPlanningRequest $request,
    ): float {
        $adjusted = $base;
        if ($response->assumptions !== []) {
            $adjusted -= 0.05 * min(3, count($response->assumptions));
        }
        if ($request->context->siteRef === '') {
            $adjusted -= 0.15;
        }
        if ($response->type === AgentPlanningResponse::TYPE_SINGLE_INTENT && $response->intent !== null) {
            $skill = $this->skills->get($response->intent->skillKey);
            if ($skill !== null) {
                $avail = $this->availability->resolve($skill, $request->context->toAvailabilityContext());
                if (! $avail->usable) {
                    $adjusted -= 0.2;
                }
                if (in_array($skill->confirmationPolicy, ['confirm', 'destructive'], true)
                    && $response->summary === '') {
                    $adjusted -= 0.1;
                }
            }
        }
        if ($response->type === AgentPlanningResponse::TYPE_EXECUTION_PLAN && $response->plan !== null) {
            $keys = array_map(static fn (AgentProposedPlanStep $s): string => $s->skillKey, $response->plan->steps);
            if (count($keys) !== count(array_unique($keys)) && count($keys) > 3) {
                $adjusted -= 0.05;
            }
        }

        return max(0.0, min(1.0, $adjusted));
    }

    private function applyConfidencePolicy(
        AgentPlanningResponse $response,
        float $adjusted,
        AgentPlanningRequest $request,
    ): AgentPlanningResponse {
        $data = $response->toArray();
        $data['adjusted_confidence'] = $adjusted;

        if ($adjusted < self::CLARIFICATION_THRESHOLD
            && in_array($response->type, [
                AgentPlanningResponse::TYPE_SINGLE_INTENT,
                AgentPlanningResponse::TYPE_EXECUTION_PLAN,
            ], true)) {
            return new AgentPlanningResponse(
                type: AgentPlanningResponse::TYPE_CLARIFICATION,
                confidence: $response->confidence,
                summary: 'Cần làm rõ thêm trước khi đề xuất thực thi.',
                clarifyingQuestions: [
                    new AgentClarifyingQuestion(
                        key: 'goal',
                        question: 'Bạn muốn làm việc nào cụ thể trong Agent Workspace?',
                        inputType: 'text',
                        required: true,
                    ),
                ],
                suggestedSkills: $this->nearestSkills($request),
                warnings: ['low_confidence'],
                adjustedConfidence: $adjusted,
            );
        }

        return AgentPlanningResponse::fromArray($data);
    }

    /**
     * @param  list<string>  $errors
     */
    private function invalidToClarificationOrUnsupported(array $errors, AgentPlanningRequest $request): AgentPlanningResponse
    {
        $joined = implode(',', $errors);
        if (str_contains($joined, 'unknown_skill') || str_contains($joined, 'internal_skill')) {
            return new AgentPlanningResponse(
                type: AgentPlanningResponse::TYPE_UNSUPPORTED,
                confidence: 0.0,
                summary: 'Agent Workspace chưa có skill phù hợp cho yêu cầu này.',
                suggestedSkills: $this->nearestSkills($request),
                warnings: $errors,
                adjustedConfidence: 0.0,
            );
        }

        return new AgentPlanningResponse(
            type: AgentPlanningResponse::TYPE_CLARIFICATION,
            confidence: 0.4,
            summary: 'Không validate được đề xuất — cần làm rõ.',
            clarifyingQuestions: [
                new AgentClarifyingQuestion(
                    key: 'goal',
                    question: 'Bạn mô tả lại mục tiêu cụ thể hơn được không?',
                    inputType: 'textarea',
                    required: true,
                ),
            ],
            suggestedSkills: $this->nearestSkills($request),
            warnings: $errors,
            adjustedConfidence: 0.4,
        );
    }

    /**
     * @return list<array{skill_key: string, name: string}>
     */
    private function nearestSkills(AgentPlanningRequest $request): array
    {
        $out = [];
        foreach ($this->skills->search($request->userMessage) as $skill) {
            if ($skill->isHidden) {
                continue;
            }
            $avail = $this->availability->resolve($skill, $request->context->toAvailabilityContext());
            if (! $avail->usable && $avail->status === 'permission_denied') {
                continue;
            }
            $out[] = ['skill_key' => $skill->key, 'name' => $skill->name];
            if (count($out) >= 5) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function successPayload(AgentPlanningResponse $response, AgentPlanningRequest $request, array $extra = []): array
    {
        $suggested = $this->suggestNextActions($request, ['suggested_skills' => $response->suggestedSkills]);

        return array_merge([
            'ok' => true,
            'code' => 'planning_'.$response->type,
            'message' => $response->summary,
            'response' => $response->toArray(),
            'ui' => $this->uiCard($response, $request, $extra['uncertain'] ?? false),
            'suggested_actions' => $suggested,
            'executed' => false,
            'auto_confirm' => false,
            'run_all' => false,
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function failurePayload(string $category, AgentPlanningRequest $request): array
    {
        $message = match ($category) {
            'model_not_configured' => 'Chưa cấu hình model AI cho Agent Planning.',
            'model_unavailable' => 'Model AI hiện không khả dụng.',
            'planning_timeout' => 'Planning timeout.',
            'rate_limited' => 'Provider đang rate-limit.',
            'structured_output_invalid' => 'Model trả JSON không hợp lệ.',
            default => 'Không lập được kế hoạch AI. Dùng slash skill (/) thay thế.',
        };

        return [
            'ok' => false,
            'code' => $category,
            'message' => $message,
            'response' => [
                'type' => AgentPlanningResponse::TYPE_UNSUPPORTED,
                'confidence' => 0.0,
                'summary' => $message,
                'suggested_skills' => $this->nearestSkills($request),
                'warnings' => [$category],
            ],
            'ui' => [
                'card' => 'unsupported',
                'title' => $message,
                'nearest_skills' => $this->nearestSkills($request),
            ],
            'executed' => false,
            'auto_confirm' => false,
            'run_all' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function uiCard(AgentPlanningResponse $response, AgentPlanningRequest $request, bool $uncertain = false): array
    {
        return match ($response->type) {
            AgentPlanningResponse::TYPE_SINGLE_INTENT => [
                'card' => 'proposed_intent',
                'uncertain' => $uncertain,
                'summary' => $response->summary,
                'skill_key' => $response->intent?->skillKey,
                'skill_name' => $response->intent !== null
                    ? ($this->skills->get($response->intent->skillKey)?->name ?? $response->intent->skillKey)
                    : null,
                'missing_fields' => $response->intent?->missingFields ?? [],
                'assumptions' => $response->assumptions,
                'warnings' => $response->warnings,
                'confidence' => $response->adjustedConfidence ?: $response->confidence,
                'input' => $response->intent?->input ?? [],
            ],
            AgentPlanningResponse::TYPE_EXECUTION_PLAN => [
                'card' => 'proposed_plan',
                'uncertain' => $uncertain,
                'summary' => $response->summary,
                'steps' => array_map(function (AgentProposedPlanStep $step) use ($request): array {
                    $skill = $this->skills->get($step->skillKey);

                    return [
                        'index' => $step->index,
                        'skill_key' => $step->skillKey,
                        'title' => $step->title !== '' ? $step->title : ($skill?->name ?? $step->skillKey),
                        'input' => $step->input,
                        'available' => $skill !== null
                            ? $this->availability->resolve($skill, $request->context->toAvailabilityContext())->usable
                            : false,
                    ];
                }, $response->plan?->steps ?? []),
                'assumptions' => $response->assumptions,
                'warnings' => $response->warnings,
                'confidence' => $response->adjustedConfidence ?: $response->confidence,
                'run_all' => false,
            ],
            AgentPlanningResponse::TYPE_CLARIFICATION => [
                'card' => 'clarification',
                'summary' => $response->summary,
                'questions' => array_map(
                    static fn (AgentClarifyingQuestion $q): array => $q->toArray(),
                    $response->clarifyingQuestions,
                ),
            ],
            AgentPlanningResponse::TYPE_ASSISTANT_ANSWER => [
                'card' => 'assistant_answer',
                'summary' => $response->summary,
                'suggested_actions' => $this->suggestNextActions($request, [
                    'suggested_skills' => $response->suggestedSkills,
                ]),
            ],
            default => [
                'card' => 'unsupported',
                'summary' => $response->summary,
                'nearest_skills' => $this->nearestSkills($request),
                'warnings' => $response->warnings,
            ],
        };
    }

    /**
     * @param  list<AgentProposedPlanStep>  $steps
     * @return list<AgentProposedPlanStep>
     */
    private function reindexSteps(array $steps): array
    {
        $out = [];
        $i = 1;
        foreach (array_values($steps) as $step) {
            $out[] = new AgentProposedPlanStep(
                index: $i,
                skillKey: $step->skillKey,
                input: $step->input,
                dependsOn: $i > 1 ? [$i - 1] : [],
                outputBindings: $step->outputBindings,
                title: $step->title,
            );
            $i++;
        }

        return $out;
    }

    private function maybeSummarize(AgentPlanningRequest $request): void
    {
        if ($this->summarizer === null) {
            return;
        }
        try {
            $count = $request->conversation->messages()->count();
            if (! $this->summarizer->shouldSummarize($count, $count * 80)) {
                return;
            }
            $messages = $request->conversation->messages()
                ->orderByDesc('id')
                ->limit(30)
                ->get()
                ->reverse()
                ->map(static fn ($m): array => [
                    'id' => $m->id,
                    'role' => $m->role,
                    'content' => $m->content,
                    'message_type' => $m->message_type,
                ])
                ->values()
                ->all();
            $summary = $this->summarizer->summarize(new AgentSummarizationRequest(
                messages: $messages,
                workingContext: $request->context->toSummary(),
            ));
            $version = (int) ($request->conversation->summary_version ?? 0) + 1;
            $this->persistence->updateConversationSummary(
                $request->conversation,
                $summary->text,
                $version,
                $summary->untilMessageId,
            );
        } catch (Throwable) {
            // Summary failure never blocks planning.
        }
    }

    private function errorCategory(Throwable $e): string
    {
        $msg = $e->getMessage();
        foreach ([
            'model_not_configured',
            'model_unavailable',
            'structured_output_invalid',
            'planning_timeout',
            'context_too_large',
            'rate_limited',
            'provider_error',
            'unsafe_response',
            'validation_failed',
        ] as $code) {
            if ($msg === $code || str_starts_with($msg, $code)) {
                return $code;
            }
        }

        return 'internal_error';
    }
}
