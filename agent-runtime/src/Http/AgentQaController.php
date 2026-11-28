<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository;
use Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentMessage;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread;
use Omnichannel\Addons\AgentRuntime\Projects\AgentSemanticSiteReadiness;
use Omnichannel\Addons\AgentRuntime\Projects\SiteDirectory;
use Omnichannel\Addons\AgentRuntime\Response\AgentPublicPayloadSanitizer;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponse;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentRunExecutionTracker;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentToolConfirmationProposal;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnResult;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

final class AgentQaController
{
    public function health(Request $request): JsonResponse
    {
        $context = $this->qaContext($request);
        if (! $context instanceof AgentQaContext) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        return new JsonResponse([
            'data' => [
                'status' => 'ok',
                'service' => 'agent-runtime-qa',
                'user_id' => $context->userId,
                'allowed_site_ids' => $context->allowedSiteIds,
                'default_tier' => 'free',
                'diagnostics_default' => true,
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    public function projects(Request $request, SiteDirectory $sites): JsonResponse
    {
        $context = $this->qaContext($request);
        if (! $context instanceof AgentQaContext) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        $catalog = $sites->listActiveSites($context->userId);
        $processingIds = [];
        if (app()->bound(AgentSemanticSiteReadiness::class)) {
            $readiness = app(AgentSemanticSiteReadiness::class);
            if ($readiness->enforcementAvailable()) {
                $processingIds = array_fill_keys(
                    $readiness->filterProcessingSiteIds(array_map(
                        static fn (array $site): int => (int) $site['id'],
                        $catalog,
                    )),
                    true,
                );
            }
        }

        $items = [];
        foreach ($catalog as $site) {
            $siteId = (int) $site['id'];
            if (! $context->allowsSiteId($siteId) || isset($processingIds[$siteId])) {
                continue;
            }
            $items[] = [
                'type' => 'site',
                'key' => 'site:'.$siteId,
                'site_id' => $siteId,
                'site_ref' => 'site:'.$siteId,
                'domain' => $site['domain'],
                'retrieval' => 'supported',
            ];
        }

        return new JsonResponse([
            'data' => [
                'projects' => $items,
                'allowed_site_ids' => $context->allowedSiteIds,
            ],
        ]);
    }

    public function turn(
        Request $request,
        AgentTurnCoordinator $coordinator,
        SiteDirectory $sites,
        AgentThreadRepository $threads,
        AgentTurnPersistence $persistence,
    ): JsonResponse {
        $context = $this->qaContext($request);
        if (! $context instanceof AgentQaContext) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $user = $request->user();
        $userId = $context->userId;

        $payload = $request->all();
        if (! is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid payload.'], 422);
        }

        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            return new JsonResponse(['message' => 'Message is required.'], 422);
        }

        // Enforce free tier only and block paid execution/consent in QA API.
        $tierRaw = $payload['tier'] ?? 'free';
        $tier = strtolower(trim((string) $tierRaw));
        if ($tier === '') {
            $tier = 'free';
        }
        if ($tier !== 'free') {
            return new JsonResponse([
                'message' => 'Paid execution is blocked in Agent QA API. Only free tier is allowed.',
                'error_code' => 'paid_execution_blocked',
            ], 403);
        }
        $paidConsent = filter_var($payload['paid_consent'] ?? ($payload['paid_confirmed'] ?? false), FILTER_VALIDATE_BOOLEAN);
        if ($paidConsent) {
            return new JsonResponse([
                'message' => 'Paid execution confirmation is not allowed in Agent QA API.',
                'error_code' => 'paid_execution_blocked',
            ], 403);
        }

        $diagnostics = array_key_exists('diagnostics', $payload)
            ? filter_var($payload['diagnostics'], FILTER_VALIDATE_BOOLEAN)
            : true;

        $threadUlid = is_string($payload['thread_ulid'] ?? null) && trim($payload['thread_ulid']) !== ''
            ? trim((string) $payload['thread_ulid'])
            : null;

        $principalType = 'user';
        $principalRef = (string) $userId;
        $existingThread = null;
        if ($threadUlid !== null) {
            $existingThread = $threads->findForPrincipal($threadUlid, $principalType, $principalRef);
            if (! $existingThread instanceof AgentThread) {
                return new JsonResponse(['message' => 'Thread not found.'], 404);
            }
            if ($existingThread->status === 'archived') {
                return new JsonResponse(['message' => 'Archived thread cannot receive new messages.'], 422);
            }
        }

        $scope = $this->resolveSiteScope($payload, $existingThread);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        if ($existingThread instanceof AgentThread && $existingThread->scope_ref !== $scope->siteRef) {
            return new JsonResponse([
                'message' => 'Thread scope does not match requested website scope.',
                'error_code' => 'thread_scope_mismatch',
            ], 422);
        }

        $siteId = (int) $scope->siteId;
        if (! $context->allowsSiteId($siteId) || ! $sites->isSiteVisible($siteId, $userId)) {
            return new JsonResponse([
                'message' => 'Site is outside allowed QA scope or inaccessible.',
                'error_code' => 'site_scope_forbidden',
            ], 403);
        }

        if ($user instanceof \App\Models\User && SeoAccessControl::roleForUser($user) === SeoAccessControl::ROLE_CONTENT_MANAGER) {
            return new JsonResponse([
                'message' => 'Mapped QA user role is restricted from site-scoped QA.',
                'error_code' => 'site_scope_forbidden',
            ], 403);
        }

        if ($grouping = $this->semanticGroupingResponse($scope, $userId)) {
            return $grouping;
        }

        $appKey = is_string($payload['app_key'] ?? null) ? trim($payload['app_key']) : 'seo-ops';
        $app = AgentApp::findByKey($appKey !== '' ? $appKey : 'seo-ops') ?? AgentApp::findByKey('seo-ops');
        if (! $app instanceof AgentApp) {
            return new JsonResponse(['message' => 'Agent app not found.'], 422);
        }

        $ownerId = ($user !== null && method_exists($user, 'accountOwnerId') ? $user->accountOwnerId() : null) ?? $userId;
        $thread = $existingThread ?? $threads->createThread(
            $app,
            $principalType,
            $principalRef,
            $userId,
            (int) $ownerId,
            $scope->type,
            $scope->siteRef ?? 'site:'.$siteId,
            $this->makeThreadTitle($message),
        );

        $clientRunUlid = is_string($payload['run_ulid'] ?? null) && trim($payload['run_ulid']) !== ''
            ? trim((string) $payload['run_ulid'])
            : null;

        $userMessage = $persistence->persistUserMessage($thread, $message);
        $run = $persistence->startRun(
            $thread,
            $userMessage,
            $app->app_key,
            $scope->type,
            $scope->siteRef ?? 'site:'.$siteId,
            $userId,
            $clientRunUlid,
        );

        $history = is_array($payload['history'] ?? null)
            ? $payload['history']
            : $this->historyBefore((int) $thread->id, (int) $userMessage->position);

        $startedAtNs = hrtime(true);

        try {
            $summary = is_array($run->retrieval_summary) ? $run->retrieval_summary : [];
            $summary['tier'] = 'free';
            $summary['routing_policy'] = AiRoutingPolicy::FreeOnly->value;
            $summary['qa_api'] = true;
            $run->update(['retrieval_summary' => $summary]);

            $result = $coordinator->send(
                $userId,
                $scope,
                $message,
                $history,
                $diagnostics,
                AiRoutingPolicy::FreeOnly,
                'free',
                (string) $run->ulid,
            );
            $durationMs = (int) round((hrtime(true) - $startedAtNs) / 1_000_000);

            if (is_array($result->gscContinuation)) {
                $response = $this->gscContinuationResponse($result, (string) $run->ulid);
                $assistant = $persistence->rememberContinuation($run, $response, $result->gscContinuation);
                $threads->touchLastMessage($thread);

                $data = [
                    'status' => 'awaiting_gsc_continuation',
                    'blocked_reason' => 'gsc_continuation_requires_user_choice',
                    ...$response->toArray(),
                    'thread_ulid' => $thread->ulid,
                    'run_ulid' => $run->ulid,
                    'user_message_id' => $run->user_message_id,
                    'assistant_message_id' => $assistant->id,
                ];
                if ($diagnostics) {
                    $data['diagnostics'] = $this->buildQaDiagnostics($result, $durationMs);
                }

                return new JsonResponse(['data' => $data]);
            }

            if ($result->confirmationProposal !== null) {
                $persistence->pauseForConfirmation($run, $result->confirmationProposal);
                $response = $this->confirmationResponse($result->confirmationProposal, (string) $run->ulid);
                $isMutation = $this->proposalHasMutation($result->confirmationProposal);

                $data = [
                    'status' => 'awaiting_confirmation',
                    'blocked_reason' => $isMutation ? 'mutation_blocked' : 'confirmation_required',
                    ...$response->toArray(),
                    'thread_ulid' => $thread->ulid,
                    'run_ulid' => $run->ulid,
                    'user_message_id' => $run->user_message_id,
                    'confirmation' => $result->confirmationProposal->clientPayload(),
                ];
                if ($diagnostics) {
                    $data['diagnostics'] = $this->buildQaDiagnostics($result, $durationMs);
                }

                return new JsonResponse(['data' => $data]);
            }

            $meta = $result->answerModelCalled ? ['answer_model' => 'called'] : [];
            if ($result->failureCode !== null) {
                $meta['failure_code'] = $result->failureCode;
            }
            if ($result->answerDiagnostics !== null) {
                $persistence->storeAnswerDiagnostics($run, $result->answerDiagnostics);
            }

            $diagnosticsPayload = $result->modelDiagnostics ?? [];
            if (is_array($result->executionTrace)) {
                $diagnosticsPayload['execution'] = $result->executionTrace;
            }
            $diagnosticsPayload['duration_ms'] = $durationMs;
            $persistence->storeModelDiagnostics($run, $diagnosticsPayload);

            $assistant = $persistence->completeRun($run, $result->response, $meta);
            $threads->touchLastMessage($thread);

            $responsePayload = $result->response instanceof AgentResponse
                ? $result->response->toArray()
                : ['message' => '', 'blocks' => [], 'actions' => [], 'sources' => []];

            $data = [
                'status' => 'done',
                ...$responsePayload,
                'thread_ulid' => $thread->ulid,
                'run_ulid' => $run->ulid,
                'user_message_id' => $run->user_message_id,
                'assistant_message_id' => $assistant->id,
            ];
            if ($diagnostics) {
                $data['diagnostics'] = $this->buildQaDiagnostics($result, $durationMs);
            }

            return new JsonResponse(['data' => $data]);
        } catch (\Throwable $e) {
            $persistence->failRun($run, 'error', $e->getMessage());
            throw $e;
        }
    }

    public function runStatus(
        Request $request,
        string $runUlid,
        SiteDirectory $sites,
    ): JsonResponse {
        $context = $this->qaContext($request);
        if (! $context instanceof AgentQaContext) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = $context->userId;

        $run = AgentRun::where('ulid', $runUlid)->first();
        if (! $run instanceof AgentRun) {
            return new JsonResponse(['message' => 'Run not found.'], 404);
        }

        if ($run->user_id !== null && (int) $run->user_id !== $userId) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $thread = $run->thread;
        if ($thread instanceof AgentThread && $thread->principal_type === 'user' && (string) $thread->principal_ref !== (string) $userId) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        if ($run->scope_type === 'site') {
            $siteId = (int) str_replace('site:', '', (string) $run->scope_ref);
            if ($siteId <= 0 || ! $context->allowsSiteId($siteId) || ! $sites->isSiteVisible($siteId, $userId)) {
                return new JsonResponse(['message' => 'Site is outside allowed QA scope or inaccessible.'], 403);
            }
        }

        $status = AgentRunExecutionTracker::statusFor($run);
        $summary = is_array($run->retrieval_summary) ? $run->retrieval_summary : [];
        $durationMs = $summary['model_diagnostics']['duration_ms'] ?? null;
        if ($durationMs === null && $run->started_at !== null && $run->finished_at !== null) {
            $durationMs = (int) $run->started_at->diffInMilliseconds($run->finished_at);
        }

        $status['thread_ulid'] = $thread?->ulid;
        $status['scope_ref'] = $run->scope_ref;
        $status['duration_ms'] = is_numeric($durationMs) ? (int) $durationMs : null;

        if (is_array($summary['model_diagnostics']['execution'] ?? null)) {
            $status['execution_trace'] = $this->sanitizeTrace($summary['model_diagnostics']['execution']);
        }

        return new JsonResponse(['data' => $status]);
    }

    public function showThread(
        Request $request,
        string $ulid,
        AgentThreadRepository $threads,
        SiteDirectory $sites,
    ): JsonResponse {
        $context = $this->qaContext($request);
        if (! $context instanceof AgentQaContext) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = $context->userId;

        $thread = $threads->findForPrincipal($ulid, 'user', (string) $userId);
        if (! $thread instanceof AgentThread) {
            return new JsonResponse(['message' => 'Thread not found.'], 404);
        }

        if ($thread->scope_type === 'site') {
            $siteId = (int) str_replace('site:', '', (string) $thread->scope_ref);
            if ($siteId <= 0 || ! $context->allowsSiteId($siteId) || ! $sites->isSiteVisible($siteId, $userId)) {
                return new JsonResponse(['message' => 'Site is outside allowed QA scope or inaccessible.'], 403);
            }
        }

        // Read-only: never auto-confirm pending runs.
        $thread->load([
            'messages' => static function ($query): void {
                $query->orderBy('position', 'asc')->take(100);
            },
            'messages.run:id,ulid,user_message_id,status,error_message,started_at,finished_at,retrieval_summary',
        ]);

        return new JsonResponse(['data' => $this->sanitizeData($thread->toArray())]);
    }

    private function qaContext(Request $request): ?AgentQaContext
    {
        $context = $request->attributes->get(AgentQaContext::REQUEST_ATTRIBUTE);

        return $context instanceof AgentQaContext ? $context : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveSiteScope(array $payload, ?AgentThread $existingThread): AgentProjectScope|JsonResponse
    {
        $siteIdRaw = $payload['site_id'] ?? null;
        if (is_numeric($siteIdRaw) && (int) $siteIdRaw > 0) {
            return AgentProjectScope::site((int) $siteIdRaw);
        }

        $scopeRaw = $payload['scope'] ?? ($payload['hostContext']['scope'] ?? null);
        if (is_array($scopeRaw)) {
            try {
                $scope = AgentProjectScope::fromArray($scopeRaw);
            } catch (InvalidArgumentException $e) {
                return new JsonResponse(['message' => $e->getMessage()], 422);
            }

            if (! $scope->isSite()) {
                return new JsonResponse([
                    'message' => 'Agent QA API requires a website (site) scope.',
                    'error_code' => 'site_scope_required',
                ], 422);
            }

            return $scope;
        }

        if ($existingThread instanceof AgentThread && $existingThread->scope_type === 'site') {
            if (preg_match('/^site:(\d+)$/', (string) $existingThread->scope_ref, $matches) === 1) {
                return AgentProjectScope::site((int) $matches[1]);
            }
        }

        return new JsonResponse([
            'message' => 'Website scope (site_id or scope.site_id) is required.',
            'error_code' => 'site_scope_required',
        ], 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildQaDiagnostics(AgentTurnResult $result, int $durationMs): array
    {
        $trace = is_array($result->executionTrace) ? $this->sanitizeTrace($result->executionTrace) : null;
        $sources = $result->response?->sources ?? [];

        $warnings = [];
        $errors = [];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }
            $sourceStatus = (string) ($source['status'] ?? '');
            $sourceName = (string) ($source['name'] ?? 'unknown');
            if (in_array($sourceStatus, ['warning', 'partial', 'degraded', 'empty'], true)) {
                $warnings[] = [
                    'source' => $sourceName,
                    'status' => $sourceStatus,
                ];
            } elseif (in_array($sourceStatus, ['error', 'failed', 'denied'], true)) {
                $errors[] = [
                    'source' => $sourceName,
                    'status' => $sourceStatus,
                    'message' => $source['error'] ?? $source['message'] ?? null,
                ];
            }
        }

        if ($result->failureCode !== null) {
            $errors[] = [
                'code' => $result->failureCode,
                'stage' => 'turn',
            ];
        }
        if (is_array($result->answerDiagnostics) && isset($result->answerDiagnostics['error'])) {
            $errors[] = [
                'code' => $result->answerDiagnostics['failure_code'] ?? 'answer_error',
                'stage' => 'answer',
                'message' => $result->answerDiagnostics['error'],
            ];
        }
        foreach ((array) ($trace['attempts'] ?? []) as $attempt) {
            if (is_array($attempt) && ($attempt['status'] ?? '') === 'failed') {
                $errors[] = [
                    'code' => $attempt['failure_code'] ?? 'model_attempt_failed',
                    'stage' => 'model',
                    'model' => $attempt['model'] ?? null,
                    'provider' => $attempt['provider'] ?? null,
                    'message' => $attempt['failure_reason'] ?? null,
                ];
            }
        }

        $retrievalSummary = [
            'module' => $trace['module'] ?? null,
            'family' => $trace['family'] ?? null,
            'tools' => $trace['tools'] ?? [],
            'parameters' => $trace['parameters'] ?? [],
            'sources' => array_values(array_map(static fn (array $source): array => [
                'name' => $source['name'] ?? null,
                'status' => $source['status'] ?? null,
                'kind' => $source['kind'] ?? null,
            ], array_filter($sources, 'is_array'))),
        ];

        $semanticScores = null;
        if (is_array($trace)) {
            $semanticScores = [
                'jev_scores' => $trace['jev_scores'] ?? null,
                'candidates' => $trace['candidates'] ?? [],
                'routing_review' => $trace['routing_review'] ?? null,
                'routing_group_id' => $trace['routing_group_id'] ?? null,
                'routing_version' => $trace['routing_version'] ?? null,
            ];
        }

        return $this->sanitizeData([
            'operation' => $trace['operation'] ?? null,
            'capabilities' => $trace['capabilities'] ?? [],
            'semantic_routing_scores' => $semanticScores,
            'retrieval_summary' => $retrievalSummary,
            'execution_trace' => $trace,
            'answer_model_called' => $result->answerModelCalled,
            'warnings' => $warnings,
            'errors' => $errors,
            'duration_ms' => $durationMs,
            'model_diagnostics' => $result->modelDiagnostics,
            'answer_diagnostics' => $result->answerDiagnostics,
        ]);
    }

    /**
     * @param  array<string, mixed>  $trace
     * @return array<string, mixed>
     */
    private function sanitizeTrace(array $trace): array
    {
        return $this->sanitizeData($trace);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizeData(array $data): array
    {
        $sanitized = (new AgentPublicPayloadSanitizer())->sanitize($data);

        return is_array($sanitized) ? $sanitized : $data;
    }

    private function proposalHasMutation(AgentToolConfirmationProposal $proposal): bool
    {
        foreach ($proposal->capabilities as $capability) {
            if (in_array($capability, ['seo_audit.improve', 'seo_audit.publish'], true)) {
                return true;
            }
            if (AgentCapabilityCatalog::known($capability) && AgentCapabilityCatalog::requiresConfirmation($capability) && $capability !== 'gsc.performance') {
                return true;
            }
        }

        return false;
    }

    private function gscContinuationResponse(AgentTurnResult $result, string $runUlid): AgentResponse
    {
        $response = $result->response ?? new AgentResponse('', [], [], []);
        $actions = array_map(static function (array $action) use ($runUlid): array {
            if (($action['type'] ?? null) === 'gsc_cached_continue' && ! isset($action['run_ulid'])) {
                $action['run_ulid'] = $runUlid;
            }

            return $action;
        }, $response->actions);

        return new AgentResponse($response->message, $response->blocks, $actions, $response->sources);
    }

    private function confirmationResponse(AgentToolConfirmationProposal $proposal, string $runUlid): AgentResponse
    {
        $labels = [];
        foreach ($proposal->toolCapabilities as $capability) {
            if (! AgentCapabilityCatalog::known($capability)) {
                continue;
            }
            $label = trim((string) (AgentCapabilityCatalog::get($capability)['label'] ?? ''));
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        $toolLabel = implode(', ', array_values(array_unique($labels)));
        $message = 'Yêu cầu này cần xác nhận trước khi sử dụng '.$toolLabel.'.';

        return new AgentResponse(
            $message,
            [['type' => 'markdown', 'text' => $message]],
            [
                ['type' => 'confirmation', 'action' => 'confirm', 'label' => 'Xác nhận', 'run_ulid' => $runUlid],
                ['type' => 'confirmation', 'action' => 'reject', 'label' => 'Từ chối', 'run_ulid' => $runUlid],
            ],
            [],
        );
    }

    private function semanticGroupingResponse(AgentProjectScope $scope, int $userId): ?JsonResponse
    {
        if ($userId <= 0 || ! $scope->isSite() || ! app()->bound(AgentSemanticSiteReadiness::class)) {
            return null;
        }

        $readiness = app(AgentSemanticSiteReadiness::class);
        if (! $readiness->enforcementAvailable()) {
            return null;
        }

        $siteId = (int) $scope->siteId;
        if ($readiness->isReady($siteId)) {
            return null;
        }

        $processing = $readiness->isProcessing($siteId);
        $message = $processing
            ? 'Website này đang được phân nhóm từ khóa ngữ nghĩa. Hãy chờ hoàn tất rồi gửi lại câu hỏi.'
            : 'Website này chưa hoàn tất phân nhóm từ khóa ngữ nghĩa.';

        return new JsonResponse([
            'data' => [
                'status' => $processing ? 'semantic_grouping_processing' : 'semantic_grouping_required',
                'message' => $message,
                'site_id' => $siteId,
            ],
        ], 422);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function historyBefore(int $threadId, int $position): array
    {
        $history = [];
        $userMessages = AgentMessage::where('thread_id', $threadId)
            ->where('role', 'user')
            ->where('position', '<', $position)
            ->orderBy('position')
            ->get();

        foreach ($userMessages as $userMessage) {
            $history[] = ['role' => 'user', 'content' => $userMessage->content];
            $latestRun = AgentRun::where('user_message_id', $userMessage->id)
                ->where('status', 'done')
                ->whereNotNull('assistant_message_id')
                ->latest('id')
                ->first();
            if ($latestRun?->assistantMessage) {
                $history[] = ['role' => 'assistant', 'content' => $latestRun->assistantMessage->content];
            }
        }

        return $history;
    }

    private function makeThreadTitle(string $message): string
    {
        $title = trim((string) preg_replace('/\s+/', ' ', $message));

        return mb_substr($title, 0, 80);
    }
}
