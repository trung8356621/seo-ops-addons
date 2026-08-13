<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution;

use Omnichannel\Addons\Agent\Enums\AgentWorkspace\AgentErrorCategory;
use Omnichannel\Addons\Agent\Enums\AgentWorkspace\AgentExecutionStatus;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentExecution;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentConversationService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentErrorPresentation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentGateway;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillAvailabilityService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillInputResolver;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentSkillDefinition;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli\AgentCliCapabilityGate;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionCancellation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionConfirmation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionPreview;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionRetry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering\AgentResultRendererRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentCapabilityResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use App\Support\RuntimeLogger;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Phase 2 orchestration — no business writes; Gateway only.
 */
final class DefaultAgentExecutionOrchestrator implements AgentExecutionOrchestrator
{
    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AgentSkillAvailabilityService $availability,
        private readonly AgentSkillInputResolver $inputResolver,
        private readonly AgentGateway $gateway,
        private readonly AgentConversationService $conversations,
        private readonly AgentErrorPresentation $errors,
        private readonly AgentExecutionStateMachine $stateMachine,
        private readonly AgentConfirmationTokenService $tokens,
        private readonly AgentExecutionIdempotencyFactory $idempotency,
        private readonly AgentExecutionContextUpdater $contextUpdater,
        private readonly AgentResultRendererRegistry $renderers,
        private readonly AgentCliCapabilityGate $capabilityGate,
    ) {}

    public function preview(AgentExecutionRequest $request): AgentExecutionPreview
    {
        $skill = $this->requireSkill($request->skillKey);
        $this->assertConversationScope($request->context, $request->conversation);

        $execution = $this->createDraftExecution($request, $skill);
        $this->transition($execution, AgentExecutionStatus::Draft, AgentExecutionStatus::Validating);

        $availability = $this->availability->resolve($skill, $request->context->toAvailabilityContext());
        $prefilled = $this->inputResolver->prefill($skill, $request->context, $request->formInput);
        $missing = $this->missingRequiredFields($skill, $prefilled);
        $normalized = $missing === []
            ? $this->inputResolver->resolve($skill, $request->context, $request->formInput)
            : $prefilled;

        $confirmation = $this->capabilityGate->confirmationForCapability(
            $skill->capability,
            $skill->confirmationPolicy,
        );
        $requiresConfirmation = $confirmation['requires'];
        $confirmationPolicy = $confirmation['policy'];
        $warnings = [];
        $effects = [];
        $previewLevel = 'orchestration';
        $gatewayState = null;
        $executable = $availability->usable && $missing === [];

        if (! $availability->usable) {
            $warnings[] = $availability->reason;
            $this->failValidation($execution, $availability->status, $availability->reason);
            $this->appendAssistantCard($request, $execution, $skill, null, kind: 'preview');

            return new AgentExecutionPreview(
                executionRef: (string) $execution->public_ref,
                skillKey: $skill->key,
                capabilityKey: $skill->capability,
                mode: $request->mode,
                executable: false,
                requiresConfirmation: $requiresConfirmation,
                confirmationPolicy: $confirmationPolicy,
                normalizedInput: $this->redactInput($normalized),
                missingFields: $missing,
                warnings: $warnings,
                effects: $effects,
                display: $this->displayMeta($skill, $request->context, $normalized),
                confirmationToken: null,
                previewLevel: $previewLevel,
                status: AgentExecutionStatus::Failed->value,
                availability: $availability->toArray(),
            );
        }

        if ($missing !== []) {
            $warnings[] = 'Thiếu trường bắt buộc: '.implode(', ', $missing);
            $this->failValidation($execution, 'validation_error', $warnings[0]);
            $this->appendAssistantCard($request, $execution, $skill, null, kind: 'preview');

            return new AgentExecutionPreview(
                executionRef: (string) $execution->public_ref,
                skillKey: $skill->key,
                capabilityKey: $skill->capability,
                mode: $request->mode,
                executable: false,
                requiresConfirmation: $requiresConfirmation,
                confirmationPolicy: $confirmationPolicy,
                normalizedInput: $this->redactInput($normalized),
                missingFields: $missing,
                warnings: $warnings,
                effects: $effects,
                display: $this->displayMeta($skill, $request->context, $normalized),
                confirmationToken: null,
                previewLevel: $previewLevel,
                status: AgentExecutionStatus::Failed->value,
                availability: $availability->toArray(),
            );
        }

        $gatewayResult = null;
        try {
            $agentContext = $this->toAgentContext($request->context, dryRun: true);
            $gatewayResult = $this->gateway->execute($agentContext, $skill->capability, $normalized);
            $previewLevel = 'gateway';
            if (! $gatewayResult->success) {
                $warnings[] = $gatewayResult->message;
                $executable = false;
            } else {
                $effects = $this->effectsFromGateway($gatewayResult);
                $gatewayState = isset($gatewayResult->meta['state_fingerprint'])
                    ? (string) $gatewayResult->meta['state_fingerprint']
                    : null;
                // Gateway may return its own confirmation token for capability-level confirm.
                if (isset($gatewayResult->data['confirmation_token']) && is_string($gatewayResult->data['confirmation_token'])) {
                    $gatewayState = hash('sha256', $gatewayResult->data['confirmation_token']);
                }
            }
            $warnings = array_values(array_unique(array_merge($warnings, $gatewayResult->warnings)));
        } catch (Throwable $e) {
            RuntimeLogger::report($e, ['orchestrator' => 'preview', 'skill' => $skill->key]);
            $warnings[] = 'Gateway preview không khả dụng — dùng orchestration preview.';
            $previewLevel = 'orchestration';
            $effects = [[
                'type' => 'orchestration',
                'label' => 'Sẽ gọi capability '.$skill->capability,
            ]];
        }

        $confirmationToken = null;
        $nextStatus = AgentExecutionStatus::Ready;
        if ($executable && $requiresConfirmation) {
            $issued = $this->tokens->issue([
                'actor_id' => $request->context->actorUserId,
                'tenant_ref' => $request->context->tenantRef,
                'site_ref' => $request->context->siteRef,
                'conversation_id' => (int) $request->conversation->id,
                'execution_ref' => (string) $execution->public_ref,
                'skill_key' => $skill->key,
                'capability_key' => $skill->capability,
                'input_hash' => $this->tokens->hashInput($normalized),
                'gateway_state' => $gatewayState,
            ]);
            $confirmationToken = $issued['token'];
            $execution->confirmation_policy = $confirmationPolicy;
            $execution->confirmation_token_hash = $issued['hash'];
            $execution->confirmation_expires_at = $issued['expires_at'];
            $nextStatus = AgentExecutionStatus::AwaitingConfirmation;
        }

        $execution->input_payload = $normalized;
        $execution->input_summary = $this->inputResolver->summarize($normalized);
        $execution->preview_payload = [
            'preview_level' => $previewLevel,
            'warnings' => $warnings,
            'effects' => $effects,
            'gateway' => $gatewayResult?->toArray(),
            'gateway_state' => $gatewayState,
        ];
        $execution->confirmation_policy = $confirmationPolicy;
        $this->transition($execution, AgentExecutionStatus::Validating, $executable ? $nextStatus : AgentExecutionStatus::Failed);
        if (! $executable) {
            $execution->error_code = 'preview_not_executable';
            $execution->error_payload = [
                'category' => AgentErrorCategory::ValidationError->value,
                'message' => $warnings[0] ?? 'Preview không executable',
            ];
            $execution->completed_at = now();
            $execution->save();
        }

        // Read / confirmation_policy=none: không append Preview card — execute path trả một result card.
        if ($requiresConfirmation || ! $executable) {
            $this->appendAssistantCard(
                $request,
                $execution,
                $skill,
                $confirmationToken,
                kind: $requiresConfirmation ? 'confirmation' : 'preview',
            );
        }

        return new AgentExecutionPreview(
            executionRef: (string) $execution->public_ref,
            skillKey: $skill->key,
            capabilityKey: $skill->capability,
            mode: $request->mode,
            executable: $executable,
            requiresConfirmation: $requiresConfirmation,
            confirmationPolicy: $confirmationPolicy,
            normalizedInput: $this->redactInput($normalized),
            missingFields: $missing,
            warnings: $warnings,
            effects: $effects,
            display: $this->displayMeta($skill, $request->context, $normalized),
            confirmationToken: $confirmationToken,
            previewLevel: $previewLevel,
            status: AgentExecutionStatus::fromStorage((string) $execution->status)->value,
            availability: $availability->toArray(),
        );
    }

    public function execute(AgentExecutionRequest $request): AgentExecutionResult
    {
        $skill = $this->requireSkill($request->skillKey);
        $this->assertConversationScope($request->context, $request->conversation);

        // UX: yes/no execution should reuse the preview execution without creating a second preview.
        // Browser must not provide secrets; only execution_ref is reused.
        $executionRef = isset($request->formInput['_execution_ref']) && is_string($request->formInput['_execution_ref'])
            ? trim($request->formInput['_execution_ref'])
            : '';
        if ($executionRef !== '') {
            $execution = $this->findExecution($executionRef, $request->context);
            // If execution is in AwaitingConfirmation, dispatchGateway() will fail closed without token.
            return $this->dispatchGateway($request->context, $execution, $skill, null);
        }

        if (in_array($skill->capability, ['agent.help', 'agent.new_chat'], true)) {
            return $this->metaResult($request, $skill);
        }

        $confirmation = $this->capabilityGate->confirmationForCapability(
            $skill->capability,
            $skill->confirmationPolicy,
        );
        if ($confirmation['requires']) {
            $preview = $this->preview($request);
            if ($preview->requiresConfirmation) {
                return new AgentExecutionResult(
                    executionRef: $preview->executionRef,
                    status: AgentExecutionStatus::fromStorage($preview->status),
                    ok: false,
                    code: 'confirmation_required',
                    message: 'Cần xác nhận trước khi thực hiện.',
                    skillKey: $skill->key,
                    capabilityKey: $skill->capability,
                    warnings: $preview->warnings,
                    meta: ['preview' => $preview->toArray()],
                    errorCategory: null,
                    error: ['category' => 'confirmation_required'],
                );
            }
            if (! $preview->executable) {
                return new AgentExecutionResult(
                    executionRef: $preview->executionRef,
                    status: AgentExecutionStatus::Failed,
                    ok: false,
                    code: 'preview_not_executable',
                    message: $preview->warnings[0] ?? 'Không thể thực thi.',
                    skillKey: $skill->key,
                    capabilityKey: $skill->capability,
                    warnings: $preview->warnings,
                    errorCategory: AgentErrorCategory::ValidationError,
                );
            }

            // Policy "preview" already previewed — continue execute on same execution.
            $execution = $this->findExecution($preview->executionRef, $request->context);
        } else {
            // Read / none — preview lightly then run.
            $preview = $this->preview($request);
            if (! $preview->executable) {
                return new AgentExecutionResult(
                    executionRef: $preview->executionRef,
                    status: AgentExecutionStatus::Failed,
                    ok: false,
                    code: 'preview_not_executable',
                    message: $preview->warnings[0] ?? 'Không thể thực thi.',
                    skillKey: $skill->key,
                    capabilityKey: $skill->capability,
                    warnings: $preview->warnings,
                    errorCategory: AgentErrorCategory::ValidationError,
                );
            }
            $execution = $this->findExecution($preview->executionRef, $request->context);
        }

        return $this->dispatchGateway($request->context, $execution, $skill, null);
    }

    public function confirm(AgentExecutionConfirmation $confirmation): AgentExecutionResult
    {
        $execution = $this->findExecution($confirmation->executionRef, $confirmation->context);
        $status = AgentExecutionStatus::fromStorage((string) $execution->status);

        if ($status->isTerminal()) {
            return $this->resultFromExecution($execution, idempotentReplay: true);
        }

        if ($status === AgentExecutionStatus::Running || $status === AgentExecutionStatus::Queued) {
            return $this->resultFromExecution($execution, idempotentReplay: true);
        }

        $this->stateMachine->assertNotTerminal($status);
        if ($status !== AgentExecutionStatus::AwaitingConfirmation && $status !== AgentExecutionStatus::Ready) {
            throw new RuntimeException('agent.execution.not_awaiting_confirmation');
        }

        $skill = $this->requireSkill((string) $execution->skill_key);
        $normalized = is_array($execution->input_payload) ? $execution->input_payload : [];
        $gatewayState = is_array($execution->preview_payload)
            ? (isset($execution->preview_payload['gateway_state']) ? (string) $execution->preview_payload['gateway_state'] : null)
            : null;

        $validation = $this->tokens->validate($confirmation->confirmationToken, [
            'actor_id' => $confirmation->context->actorUserId,
            'tenant_ref' => $confirmation->context->tenantRef,
            'site_ref' => $confirmation->context->siteRef,
            'conversation_id' => (int) $execution->conversation_id,
            'execution_ref' => (string) $execution->public_ref,
            'skill_key' => (string) $execution->skill_key,
            'capability_key' => (string) $execution->capability,
            'input_hash' => $this->tokens->hashInput($normalized),
            'gateway_state' => $gatewayState,
            'stored_hash' => $execution->confirmation_token_hash,
        ]);

        if ($validation !== 'ok') {
            $category = match ($validation) {
                'expired' => AgentErrorCategory::ConfirmationExpired,
                'stale', 'input_mismatch' => AgentErrorCategory::ConfirmationStale,
                'actor_mismatch', 'site_mismatch', 'conversation_mismatch' => AgentErrorCategory::PermissionDenied,
                'already_used' => AgentErrorCategory::Conflict,
                default => AgentErrorCategory::ValidationError,
            };

            return new AgentExecutionResult(
                executionRef: (string) $execution->public_ref,
                status: $status,
                ok: false,
                code: 'confirmation_'.$validation,
                message: $this->errors->present($category->value, 'Xác nhận không hợp lệ ('.$validation.').'),
                skillKey: (string) $execution->skill_key,
                capabilityKey: (string) $execution->capability,
                errorCategory: $category,
                error: ['category' => $category->value, 'reason' => $validation],
                attempt: (int) ($execution->attempt ?? 1),
            );
        }

        $this->tokens->consume($confirmation->confirmationToken);
        $execution->confirmed_at = now();
        $execution->confirmed_by = $confirmation->context->actorUserId;
        $execution->save();

        return $this->dispatchGateway(
            $confirmation->context,
            $execution,
            $skill,
            $confirmation->confirmationToken,
        );
    }

    public function cancel(AgentExecutionCancellation $cancellation): AgentExecutionResult
    {
        $execution = $this->findExecution($cancellation->executionRef, $cancellation->context);
        $status = AgentExecutionStatus::fromStorage((string) $execution->status);

        if ($status->isTerminal()) {
            return $this->resultFromExecution($execution, idempotentReplay: true);
        }

        if ($status->isCancellableWithoutGateway()) {
            $this->transition($execution, $status, AgentExecutionStatus::Cancelled);
            $execution->cancelled_at = now();
            $execution->completed_at = now();
            $execution->save();

            return $this->resultFromExecution($execution);
        }

        if ($status === AgentExecutionStatus::Running) {
            // Phase 2: no generic cancel capability on Gateway — do not fake cancelled.
            return new AgentExecutionResult(
                executionRef: (string) $execution->public_ref,
                status: $status,
                ok: false,
                code: 'cancel_unsupported',
                message: 'Execution đang chạy và capability không hỗ trợ cancel.',
                skillKey: (string) $execution->skill_key,
                capabilityKey: (string) $execution->capability,
                operationReference: $execution->operation_ref,
                errorCategory: AgentErrorCategory::Conflict,
                attempt: (int) ($execution->attempt ?? 1),
            );
        }

        throw new RuntimeException('agent.execution.cancel_forbidden');
    }

    public function retry(AgentExecutionRetry $retry): AgentExecutionResult
    {
        $parent = $this->findExecution($retry->executionRef, $retry->context);
        $status = AgentExecutionStatus::fromStorage((string) $parent->status);
        if (! $status->isTerminal()) {
            throw new RuntimeException('agent.execution.retry_only_terminal');
        }

        $category = AgentErrorCategory::fromGatewayCode((string) ($parent->error_code ?? 'internal'));
        if ($status === AgentExecutionStatus::Failed && ! $category->retryable() && in_array($category, [
            AgentErrorCategory::PermissionDenied,
            AgentErrorCategory::ValidationError,
            AgentErrorCategory::NotConfigured,
        ], true)) {
            // Still allow retry path structurally but re-preview will re-check.
        }

        $attempt = (int) ($parent->attempt ?? 1) + 1;
        $request = new AgentExecutionRequest(
            context: $retry->context,
            conversation: $parent->conversation,
            skillKey: (string) $parent->skill_key,
            formInput: is_array($parent->input_payload) ? $parent->input_payload : [],
            mode: (string) ($parent->mode ?: 'execute'),
            parentExecutionRef: (string) $parent->public_ref,
            planRef: null,
            stepIndex: $parent->step_index !== null ? (int) $parent->step_index : null,
            attempt: $attempt,
        );

        $skill = $this->requireSkill($request->skillKey);
        $confirmation = $this->capabilityGate->confirmationForCapability(
            $skill->capability,
            $skill->confirmationPolicy,
        );
        if ($confirmation['requires']) {
            $preview = $this->preview($request);
            return new AgentExecutionResult(
                executionRef: $preview->executionRef,
                status: AgentExecutionStatus::fromStorage($preview->status),
                ok: false,
                code: $preview->requiresConfirmation ? 'confirmation_required' : ($preview->executable ? 'ready' : 'preview_not_executable'),
                message: $preview->requiresConfirmation
                    ? 'Retry cần xác nhận lại.'
                    : ($preview->warnings[0] ?? 'Retry đã tạo execution mới.'),
                skillKey: $skill->key,
                capabilityKey: $skill->capability,
                warnings: $preview->warnings,
                meta: ['preview' => $preview->toArray(), 'parent_execution_ref' => $parent->public_ref],
                attempt: $attempt,
            );
        }

        return $this->execute($request);
    }

    private function dispatchGateway(
        AgentWorkspaceContext $context,
        SeoAgentExecution $execution,
        AgentSkillDefinition $skill,
        ?string $confirmationToken,
    ): AgentExecutionResult {
        $status = AgentExecutionStatus::fromStorage((string) $execution->status);
        if ($status === AgentExecutionStatus::Running || $status === AgentExecutionStatus::Succeeded) {
            return $this->resultFromExecution($execution, idempotentReplay: true);
        }

        if ($status === AgentExecutionStatus::AwaitingConfirmation && $confirmationToken === null) {
            throw new RuntimeException('agent.execution.confirmation_required');
        }

        $from = $status;
        if ($from === AgentExecutionStatus::AwaitingConfirmation || $from === AgentExecutionStatus::Ready) {
            $this->transition($execution, $from, AgentExecutionStatus::Running);
        } elseif ($from === AgentExecutionStatus::Validating) {
            $this->transition($execution, $from, AgentExecutionStatus::Ready);
            $this->transition($execution, AgentExecutionStatus::Ready, AgentExecutionStatus::Running);
        } else {
            $this->stateMachine->assertNotTerminal($from);
            $this->transition($execution, $from, AgentExecutionStatus::Running);
        }

        $normalized = is_array($execution->input_payload) ? $execution->input_payload : [];
        $attempt = (int) ($execution->attempt ?? 1);
        if ($execution->idempotency_key === null || $execution->idempotency_key === '') {
            $execution->idempotency_key = $this->idempotency->make((string) $execution->public_ref, $attempt);
        }
        $execution->started_at = $execution->started_at ?? now();
        $execution->save();

        $agentContext = $this->toAgentContext(
            $context,
            dryRun: false,
            confirmationToken: $confirmationToken,
            idempotencyKey: (string) $execution->idempotency_key,
        );

        try {
            $gatewayResult = $this->gateway->execute($agentContext, $skill->capability, $normalized);
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'orchestrator' => 'execute',
                'execution' => $execution->public_ref,
                'skill' => $skill->key,
            ]);
            $gatewayResult = AgentCapabilityResult::fail(
                'internal_error',
                'Lỗi nội bộ khi gọi Gateway.',
            );
        }

        return $this->finalize($context, $execution, $skill, $gatewayResult);
    }

    private function finalize(
        AgentWorkspaceContext $context,
        SeoAgentExecution $execution,
        AgentSkillDefinition $skill,
        AgentCapabilityResult $gatewayResult,
    ): AgentExecutionResult {
        $operationRef = null;
        if (isset($gatewayResult->data['operation_ref']) && is_string($gatewayResult->data['operation_ref'])) {
            $operationRef = $gatewayResult->data['operation_ref'];
        } elseif (isset($gatewayResult->meta['operation_ref']) && is_string($gatewayResult->meta['operation_ref'])) {
            $operationRef = $gatewayResult->meta['operation_ref'];
        }

        $next = $gatewayResult->success ? AgentExecutionStatus::Succeeded : AgentExecutionStatus::Failed;
        $this->transition($execution, AgentExecutionStatus::Running, $next);

        $safeData = $this->sanitizeResultData($gatewayResult);
        $errorCategory = $gatewayResult->success ? null : AgentErrorCategory::fromGatewayCode($gatewayResult->code);

        $execution->operation_ref = $operationRef;
        $execution->result_payload = [
            'code' => $gatewayResult->code,
            'message' => $gatewayResult->message,
            'data' => $safeData,
            'warnings' => $gatewayResult->warnings,
            'next_actions' => $gatewayResult->nextActions,
            'meta' => $this->sanitizeMeta($gatewayResult->meta),
        ];
        $execution->result_summary = [
            'code' => $gatewayResult->code,
            'message' => $gatewayResult->message,
            'data_keys' => array_keys($safeData),
            'warnings' => $gatewayResult->warnings,
        ];
        $execution->error_code = $gatewayResult->success ? null : $gatewayResult->code;
        $execution->error_payload = $gatewayResult->success ? null : [
            'category' => $errorCategory?->value,
            'code' => $gatewayResult->code,
            'message' => $this->errors->present($gatewayResult->code, $gatewayResult->message),
        ];
        $execution->completed_at = now();
        $execution->save();

        $result = new AgentExecutionResult(
            executionRef: (string) $execution->public_ref,
            status: $next,
            ok: $gatewayResult->success,
            code: $gatewayResult->code,
            message: $gatewayResult->success
                ? $gatewayResult->message
                : $this->errors->present($gatewayResult->code, $gatewayResult->message),
            skillKey: $skill->key,
            capabilityKey: $skill->capability,
            data: $safeData,
            warnings: $gatewayResult->warnings,
            nextActions: $gatewayResult->nextActions,
            meta: $this->sanitizeMeta($gatewayResult->meta),
            operationReference: $operationRef,
            errorCategory: $errorCategory,
            error: $execution->error_payload,
            attempt: (int) ($execution->attempt ?? 1),
            idempotentReplay: (bool) ($gatewayResult->meta['idempotent_replay'] ?? false),
        );

        $rendered = $this->renderers->render($result);
        $result = new AgentExecutionResult(
            executionRef: $result->executionRef,
            status: $result->status,
            ok: $result->ok,
            code: $result->code,
            message: $result->message,
            skillKey: $result->skillKey,
            capabilityKey: $result->capabilityKey,
            data: $result->data,
            warnings: $result->warnings,
            nextActions: $result->nextActions,
            meta: $result->meta,
            operationReference: $result->operationReference,
            errorCategory: $result->errorCategory,
            error: $result->error,
            rendered: $rendered,
            attempt: $result->attempt,
            idempotentReplay: $result->idempotentReplay,
        );

        $conversation = $execution->conversation;
        if ($result->ok) {
            $this->contextUpdater->apply($conversation, $result);
        }

        $hideEnvelope = (bool) ($rendered['hide_envelope'] ?? false);
        // One display surface: when hide_envelope card owns body, keep message content empty.
        $userContent = '';
        if (! $hideEnvelope) {
            $userContent = $result->message;
        }

        $message = $this->conversations->appendMessage(
            $conversation,
            role: 'assistant',
            messageType: $result->ok ? 'execution_result' : 'execution_error',
            content: $userContent,
            structured: [
                'execution' => $hideEnvelope
                    ? [
                        'ok' => $result->ok,
                        'status' => $result->status->value,
                        'skill_key' => $result->skillKey,
                        'capability_key' => $result->capabilityKey,
                    ]
                    : $result->toArray(),
                'rendered' => $rendered,
            ],
            skillKey: $skill->key,
            operationRef: $hideEnvelope ? null : $operationRef,
            createdBy: $context->actorUserId,
        );
        $execution->message_id = $message->id;
        $execution->save();

        return $result;
    }

    private function createDraftExecution(AgentExecutionRequest $request, AgentSkillDefinition $skill): SeoAgentExecution
    {
        $attempt = $request->attempt ?? 1;
        $ref = 'aex_'.Str::lower((string) Str::ulid());

        $parentId = null;
        if ($request->parentExecutionRef) {
            $parent = SeoAgentExecution::query()
                ->where('public_ref', $request->parentExecutionRef)
                ->where('conversation_id', $request->conversation->id)
                ->first();
            $parentId = $parent?->id;
        }

        return SeoAgentExecution::query()->create([
            'public_ref' => $ref,
            'conversation_id' => $request->conversation->id,
            'parent_execution_id' => $parentId,
            'plan_id' => null,
            'step_index' => $request->stepIndex,
            'skill_key' => $skill->key,
            'capability' => $skill->capability,
            'mode' => $request->mode,
            'status' => AgentExecutionStatus::Draft->value,
            'attempt' => $attempt,
            'idempotency_key' => $this->idempotency->make($ref, $attempt),
            'input_payload' => $request->formInput,
            'input_summary' => $this->inputResolver->summarize(
                $this->inputResolver->prefill($skill, $request->context, $request->formInput),
            ),
        ]);
    }

    private function transition(SeoAgentExecution $execution, AgentExecutionStatus $from, AgentExecutionStatus $to): void
    {
        $current = AgentExecutionStatus::fromStorage((string) $execution->status);
        if ($current !== $from) {
            // Allow legacy alias equality after mapping.
            if ($current->value !== $from->value) {
                throw new RuntimeException(sprintf(
                    'agent.execution.status_mismatch:expected_%s_got_%s',
                    $from->value,
                    $current->value,
                ));
            }
        }
        $this->stateMachine->assertTransition($from, $to);
        $execution->status = $to->toStorage();
        $execution->save();
    }

    private function failValidation(SeoAgentExecution $execution, string $code, string $message): void
    {
        $this->transition($execution, AgentExecutionStatus::Validating, AgentExecutionStatus::Failed);
        $execution->error_code = $code;
        $execution->error_payload = [
            'category' => AgentErrorCategory::fromGatewayCode($code)->value,
            'message' => $message,
        ];
        $execution->completed_at = now();
        $execution->save();
    }

    /**
     * @return list<string>
     */
    private function missingRequiredFields(AgentSkillDefinition $skill, array $formInput): array
    {
        $missing = [];
        foreach ($skill->formSchema as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $required = (bool) ($field['required'] ?? false);
            if (! $required) {
                continue;
            }
            $value = $formInput[$key] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === [])) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function displayMeta(AgentSkillDefinition $skill, AgentWorkspaceContext $context, array $normalized): array
    {
        $itemCount = null;
        if (isset($normalized['item_refs']) && is_array($normalized['item_refs'])) {
            $itemCount = count($normalized['item_refs']);
        } elseif (isset($normalized['selected_item_refs']) && is_array($normalized['selected_item_refs'])) {
            $itemCount = count($normalized['selected_item_refs']);
        }

        $attributes = is_array($normalized['attributes'] ?? null) ? $normalized['attributes'] : [];
        $assigneeId = $attributes['user_id']
            ?? $attributes['assignee_ref']
            ?? $normalized['assignee_ref']
            ?? null;

        return [
            'action' => $skill->name,
            'slash_command' => $skill->slashCommand,
            'site' => $context->siteName,
            'project_ref' => $normalized['project_ref'] ?? $context->projectRef,
            'workspace_ref' => $normalized['workspace_ref'] ?? $context->workspaceRef,
            'project_name' => $attributes['name'] ?? $normalized['project_name'] ?? null,
            'month' => $attributes['month'] ?? $normalized['month'] ?? null,
            'member_id' => $assigneeId !== null && $assigneeId !== '' ? (string) $assigneeId : null,
            'affected_item_count' => $itemCount,
        ];
    }

    /**
     * @return list<array{type: string, label: string, count?: int|null, ref?: string|null}>
     */
    private function effectsFromGateway(AgentCapabilityResult $result): array
    {
        $effects = [];
        if (isset($result->data['effects']) && is_array($result->data['effects'])) {
            foreach ($result->data['effects'] as $effect) {
                if (is_array($effect) && isset($effect['label'])) {
                    $effects[] = [
                        'type' => (string) ($effect['type'] ?? 'effect'),
                        'label' => (string) $effect['label'],
                        'count' => isset($effect['count']) ? (int) $effect['count'] : null,
                        'ref' => isset($effect['ref']) ? (string) $effect['ref'] : null,
                    ];
                }
            }
        }
        if ($effects === []) {
            $effects[] = [
                'type' => 'capability',
                'label' => $result->message !== '' ? $result->message : 'Gateway preview OK',
            ];
        }

        return $effects;
    }

    private function appendAssistantCard(
        AgentExecutionRequest $request,
        SeoAgentExecution $execution,
        AgentSkillDefinition $skill,
        ?string $confirmationToken,
        string $kind,
    ): void {
        $status = AgentExecutionStatus::fromStorage((string) $execution->status);
        $structured = [
            'execution_ref' => $execution->public_ref,
            'skill_key' => $skill->key,
            'capability_key' => $skill->capability,
            'status' => $status->value,
            'confirmation_policy' => $skill->confirmationPolicy,
            'preview' => $execution->preview_payload,
            'input_summary' => is_array($execution->input_summary) ? $execution->input_summary : [],
            'display' => $this->displayMeta(
                $skill,
                $request->context,
                is_array($execution->input_payload) ? $execution->input_payload : [],
            ),
            'requires_confirmation' => $status === AgentExecutionStatus::AwaitingConfirmation,
        ];
        // UX contract: never expose raw confirmation token to browser.
        // confirmationToken is used server-side only by confirm() flow.

        $message = $this->conversations->appendMessage(
            $request->conversation,
            role: 'assistant',
            messageType: $kind === 'confirmation' ? 'execution_confirmation' : 'execution_preview',
            content: $kind === 'confirmation'
                ? 'Xác nhận hành động: '.$skill->name
                : 'Preview: '.$skill->name,
            structured: $structured,
            skillKey: $skill->key,
            createdBy: $request->context->actorUserId,
        );
        $execution->message_id = $message->id;
        $execution->save();
    }

    private function findExecution(string $ref, AgentWorkspaceContext $context): SeoAgentExecution
    {
        $execution = SeoAgentExecution::query()->where('public_ref', $ref)->first();
        if ($execution === null) {
            throw new RuntimeException('agent.execution.not_found');
        }

        $conversation = $execution->conversation;
        if ($conversation === null) {
            throw new RuntimeException('agent.execution.conversation_missing');
        }
        $this->assertConversationScope($context, $conversation);

        return $execution;
    }

    private function assertConversationScope(AgentWorkspaceContext $context, $conversation): void
    {
        if ((int) $conversation->site_id !== (int) $context->siteId) {
            throw new RuntimeException('agent.execution.site_mismatch');
        }
        if ($context->actorUserId > 0 && (int) $conversation->created_by !== (int) $context->actorUserId) {
            // Allow manager? Phase 2: fail closed on creator mismatch for execution ops.
            // Soften: if created_by null, allow.
            if ($conversation->created_by !== null) {
                throw new RuntimeException('agent.execution.actor_mismatch');
            }
        }
    }

    private function requireSkill(string $skillKey): AgentSkillDefinition
    {
        $skill = $this->skills->get($skillKey) ?? $this->skills->resolveSlashCommand($skillKey);
        if ($skill === null) {
            throw new RuntimeException('agent.skill_not_found');
        }

        return $skill;
    }

    private function resultFromExecution(SeoAgentExecution $execution, bool $idempotentReplay = false): AgentExecutionResult
    {
        $payload = is_array($execution->result_payload) ? $execution->result_payload : [];
        $status = AgentExecutionStatus::fromStorage((string) $execution->status);
        $ok = $status === AgentExecutionStatus::Succeeded;
        $errorCategory = $ok ? null : AgentErrorCategory::fromGatewayCode((string) ($execution->error_code ?? 'internal'));

        $result = new AgentExecutionResult(
            executionRef: (string) $execution->public_ref,
            status: $status,
            ok: $ok,
            code: (string) ($payload['code'] ?? $execution->error_code ?? $status->value),
            message: (string) ($payload['message'] ?? ($ok ? 'OK' : 'Failed')),
            skillKey: (string) $execution->skill_key,
            capabilityKey: (string) $execution->capability,
            data: is_array($payload['data'] ?? null) ? $payload['data'] : [],
            warnings: is_array($payload['warnings'] ?? null) ? $payload['warnings'] : [],
            nextActions: is_array($payload['next_actions'] ?? null) ? $payload['next_actions'] : [],
            meta: is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            operationReference: $execution->operation_ref,
            errorCategory: $errorCategory,
            error: is_array($execution->error_payload) ? $execution->error_payload : null,
            attempt: (int) ($execution->attempt ?? 1),
            idempotentReplay: $idempotentReplay,
        );

        return new AgentExecutionResult(
            executionRef: $result->executionRef,
            status: $result->status,
            ok: $result->ok,
            code: $result->code,
            message: $result->message,
            skillKey: $result->skillKey,
            capabilityKey: $result->capabilityKey,
            data: $result->data,
            warnings: $result->warnings,
            nextActions: $result->nextActions,
            meta: $result->meta,
            operationReference: $result->operationReference,
            errorCategory: $result->errorCategory,
            error: $result->error,
            rendered: $this->renderers->render($result),
            attempt: $result->attempt,
            idempotentReplay: $result->idempotentReplay,
        );
    }

    private function metaResult(AgentExecutionRequest $request, AgentSkillDefinition $skill): AgentExecutionResult
    {
        $ref = 'aex_'.Str::lower((string) Str::ulid());
        $execution = SeoAgentExecution::query()->create([
            'public_ref' => $ref,
            'conversation_id' => $request->conversation->id,
            'skill_key' => $skill->key,
            'capability' => $skill->capability,
            'mode' => 'meta',
            'status' => AgentExecutionStatus::Succeeded->value,
            'attempt' => 1,
            'idempotency_key' => $this->idempotency->make($ref, 1),
            'started_at' => now(),
            'completed_at' => now(),
            'result_summary' => ['code' => 'ok', 'message' => 'meta'],
        ]);

        return new AgentExecutionResult(
            executionRef: $ref,
            status: AgentExecutionStatus::Succeeded,
            ok: true,
            code: 'ok',
            message: $skill->capability === 'agent.new_chat' ? 'Tạo chat mới.' : 'Help',
            skillKey: $skill->key,
            capabilityKey: $skill->capability,
            data: ['action' => $skill->capability === 'agent.new_chat' ? 'new_chat' : 'help'],
            attempt: 1,
        );
    }

    private function toAgentContext(
        AgentWorkspaceContext $context,
        bool $dryRun = false,
        ?string $confirmationToken = null,
        ?string $idempotencyKey = null,
    ): AgentExecutionContext {
        return new AgentExecutionContext(
            actorRef: $context->actorRef,
            actorType: 'agent',
            tenantRef: $context->tenantRef,
            siteRef: $context->siteRef,
            requestRef: 'aw_'.Str::lower((string) Str::ulid()),
            sessionRef: null,
            idempotencyKey: $idempotencyKey,
            confirmationToken: $confirmationToken,
            dryRun: $dryRun,
            resolvedSiteId: $context->siteId,
            resolvedActorUserId: $context->actorUserId,
            scopes: $context->scopes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeResultData(AgentCapabilityResult $result): array
    {
        $data = $result->data;
        foreach (['api_key', 'token', 'authorization', 'password', 'secret', 'confirmation_token'] as $secret) {
            unset($data[$secret]);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function sanitizeMeta(array $meta): array
    {
        foreach (['api_key', 'token', 'authorization', 'password', 'secret', 'confirmation_token'] as $secret) {
            unset($meta[$secret]);
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function redactInput(array $input): array
    {
        foreach (['api_key', 'token', 'authorization', 'password', 'secret'] as $secret) {
            if (array_key_exists($secret, $input)) {
                $input[$secret] = '[redacted]';
            }
        }

        return $input;
    }
}
