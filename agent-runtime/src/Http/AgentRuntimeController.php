<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Http;

use Omnichannel\Addons\AgentRuntime\Catalog\AgentTestCatalogService;
use Omnichannel\Addons\AgentRuntime\Catalog\AgentTestArticleCatalogService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Projects\SiteDirectory;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnResult;
use Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository;
use Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentMessage;
use Omnichannel\Addons\AgentRuntime\Model\AssumedModelResolver;
use Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput;
use Omnichannel\Addons\AgentRuntime\Decision\RoutingDecisionRejected;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnProgress;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentConfirmedToolExecutor;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentToolConfirmationProposal;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponse;
use Omnichannel\Addons\AgentRuntime\Testing\AgentTestExecutionService;
use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeService;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextSource;
use Omnichannel\Addons\AgentRuntime\Welcome\AgentWelcomeQuestions;

final class AgentRuntimeController
{
    public function confirmRun(
        Request $request,
        string $runUlid,
        SiteDirectory $sites,
        AgentTurnPersistence $persistence,
        AgentThreadRepository $threads,
        AgentConfirmedToolExecutor $tools,
        AgentTurnCoordinator $coordinator,
        ?AssumedModelResolver $modelResolver = null,
    ): JsonResponse {
        $user = $request->user();
        if ($user === null || (int) $user->id <= 0) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;
        $run = $persistence->claimAwaitingConfirmation($runUlid, $userId);
        if (! $run instanceof AgentRun) {
            return new JsonResponse(['message' => 'Confirmation run not found or already finished.'], 409);
        }

        try {
            $summary = is_array($run->retrieval_summary) ? $run->retrieval_summary : [];
            $proposal = AgentToolConfirmationProposal::fromArray((array) ($summary['confirmation']['proposal'] ?? []));
            $scope = AgentProjectScope::fromArray($proposal->scope);
            if (! $scope->isSite() || ! $sites->isSiteVisible($scope->siteId, $userId)) {
                $persistence->failRun($run, 'site_access_denied', 'Site is invalid or inaccessible.');

                return new JsonResponse(['message' => 'Site is invalid or inaccessible.'], 403);
            }

            $thread = $run->thread()->firstOrFail();
            $userMessage = $run->userMessage()->firstOrFail();
            $history = $this->historyBefore((int) $thread->id, (int) $userMessage->position);
            $bundle = $tools->execute($proposal, $userId);
            $factual = $coordinator->composeFactual($bundle, (string) $userMessage->content, $proposal->responseLanguage);
            if ($factual instanceof AgentResponse) {
                $assistant = $persistence->completeRun($run, $factual);
                $execution = [
                    'synthesis' => false,
                    'external_model' => null,
                    'external_model_calls' => 0,
                    'capabilities' => $proposal->capabilities,
                    'tools' => array_map(static fn ($source): string => $source->name, $bundle->sources),
                ];
                $persistence->storeModelDiagnostics($run, ['execution' => $execution]);
                $threads->touchLastMessage($thread);

                return new JsonResponse(['data' => [
                    ...$factual->toArray(),
                    'thread_ulid' => $thread->ulid,
                    'run_ulid' => $run->ulid,
                    'user_message_id' => $run->user_message_id,
                    'assistant_message_id' => $assistant->id,
                    'execution' => $this->clientExecution($execution, $factual->sources, false),
                ]]);
            }
            $confirmationState = (array) ($summary['confirmation']['runtime_state'] ?? []);
            if (($confirmationState['debug'] ?? false) === true) {
                $modelResolver ??= app()->bound(AssumedModelResolver::class) ? app(AssumedModelResolver::class) : null;
                if (! $modelResolver instanceof AssumedModelResolver) {
                    throw new \LogicException('Debug confirmation requires an assumed model resolver.');
                }
                $routingInput = $coordinator->buildRoutingInput($scope, (string) $userMessage->content, $history);
                $answerInput = $coordinator->buildAnswerInput(
                    $scope,
                    (string) $userMessage->content,
                    $history,
                    $bundle,
                    $proposal->responseTemplate,
                    $proposal->responseLanguage,
                );
                $persistence->pauseRun($run, 'answer', [
                    'routing_input' => ['stage' => $routingInput->stage, 'messages' => $routingInput->messages],
                    'bundle' => $bundle->toArray(),
                    'selected_response_template' => $proposal->responseTemplate,
                    'selected_response_language' => $proposal->responseLanguage,
                    'turn' => ['scope' => $scope->toArray(), 'message' => (string) $userMessage->content, 'history' => $history],
                ]);
                $input = $answerInput->exportText();

                return new JsonResponse(['data' => [
                    'status' => 'paused',
                    'run_ulid' => $run->ulid,
                    'thread_ulid' => $thread->ulid,
                    'user_message_id' => $run->user_message_id,
                    'model_call' => [
                        'key' => 'answer',
                        'full_prompt' => $input,
                        'prompt_size' => mb_strlen($input),
                        'assumed_model' => $modelResolver->resolveAnswerModel($userId)->toArray(),
                    ],
                ]]);
            }
            $result = $coordinator->answerConfirmed(
                $userId,
                $scope,
                (string) $userMessage->content,
                $history,
                $bundle,
                $proposal->responseTemplate,
                $proposal->responseLanguage,
            );
            $meta = $result->answerModelCalled ? ['answer_model' => 'called'] : [];
            if ($result->failureCode !== null) {
                $meta['failure_code'] = $result->failureCode;
            }
            $assistant = $persistence->completeRun($run, $result->response, $meta);
            $threads->touchLastMessage($thread);
            $data = $result->response->toArray();
            $data['thread_ulid'] = $thread->ulid;
            $data['run_ulid'] = $run->ulid;
            $data['user_message_id'] = $run->user_message_id;
            $data['assistant_message_id'] = $assistant->id;
            $confirmedExecution = $this->rememberExecution($persistence, $run, $result->executionTrace, $result->response?->sources ?? [], $result->answerModelCalled);
            if ($confirmedExecution !== null) {
                $data['execution'] = $confirmedExecution;
            }

            return new JsonResponse(['data' => $data]);
        } catch (InvalidArgumentException $e) {
            $persistence->failRun($run, 'confirmed_tool_unavailable', $e->getMessage());

            return new JsonResponse(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $persistence->failRun($run, 'error', $e->getMessage());
            throw $e;
        }
    }

    public function rejectRun(
        Request $request,
        string $runUlid,
        SiteDirectory $sites,
        AgentTurnPersistence $persistence,
        AgentThreadRepository $threads,
        AgentConfirmedToolExecutor $tools,
        AgentTurnCoordinator $coordinator,
        GscContextSource $gsc,
    ): JsonResponse {
        $user = $request->user();
        if ($user === null || (int) $user->id <= 0) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;
        $run = $persistence->claimAwaitingConfirmation($runUlid, $userId);
        if (! $run instanceof AgentRun) {
            return new JsonResponse(['message' => 'Confirmation run not found or already finished.'], 409);
        }

        try {
            $summary = is_array($run->retrieval_summary) ? $run->retrieval_summary : [];
            $proposal = AgentToolConfirmationProposal::fromArray((array) ($summary['confirmation']['proposal'] ?? []));
            $scope = AgentProjectScope::fromArray($proposal->scope);
            if (! $scope->isSite() || ! $sites->isSiteVisible($scope->siteId, $userId)) {
                $persistence->failRun($run, 'site_access_denied', 'Site is invalid or inaccessible.');

                return new JsonResponse(['message' => 'Site is invalid or inaccessible.'], 403);
            }
            $tools->validateProposal($proposal);
            $thread = $run->thread()->firstOrFail();

            if ($proposal->toolCapabilities !== ['gsc.performance']) {
                return $this->completeDeterministicRun(
                    $run,
                    $thread,
                    $persistence,
                    $threads,
                    'Đã từ chối yêu cầu.',
                );
            }

            $latestPeriod = $gsc->latestSyncedPeriod((int) $scope->siteId);
            if ($latestPeriod === null) {
                return $this->completeDeterministicRun(
                    $run,
                    $thread,
                    $persistence,
                    $threads,
                    'Không có dữ liệu GSC đã đồng bộ để sử dụng thay thế.',
                );
            }

            $requestedPeriod = is_string($proposal->parameters['period'] ?? null)
                ? $proposal->parameters['period']
                : null;
            $fallbackProposal = new AgentToolConfirmationProposal(
                $proposal->intent,
                $proposal->primaryCapability,
                $proposal->capabilities,
                [...$proposal->parameters, 'period' => $latestPeriod],
                $proposal->responseTemplate,
                $proposal->responseLanguage,
                $proposal->toolCapabilities,
                $proposal->scope,
            );
            $bundle = $tools->execute($fallbackProposal, $userId);
            $bundle = new RetrievalBundle(
                $bundle->scope,
                [...$bundle->sources, new RetrievalSource('gsc_fallback_policy', 'ok', 'backend_policy', [
                    'requested_period' => $requestedPeriod,
                    'fallback_period' => $latestPeriod,
                    'reason' => 'requested_gsc_tool_rejected',
                ])],
                $bundle->warnings,
            );
            $userMessage = $run->userMessage()->firstOrFail();
            $history = $this->historyBefore((int) $thread->id, (int) $userMessage->position);
            $result = $coordinator->answerConfirmed(
                $userId,
                $scope,
                (string) $userMessage->content,
                $history,
                $bundle,
                $proposal->responseTemplate,
                $proposal->responseLanguage,
            );
            $notice = 'Bạn đã từ chối dữ liệu GSC theo kỳ yêu cầu. Agent sử dụng dữ liệu đã đồng bộ gần nhất: '
                .$this->formatPeriod($latestPeriod).'.';
            $modelResponse = $result->response;
            $response = new AgentResponse(
                trim($notice.' '.($modelResponse?->message ?? '')),
                [['type' => 'markdown', 'text' => $notice], ...($modelResponse?->blocks ?? [])],
                $modelResponse?->actions ?? [],
                $modelResponse?->sources ?? [],
            );
            $assistant = $persistence->completeRun($run, $response, ['answer_model' => 'called']);
            $threads->touchLastMessage($thread);

            $fallbackExecution = $this->rememberExecution(
                $persistence,
                $run,
                $result->executionTrace,
                $response->sources,
                true,
            );

            return new JsonResponse(['data' => [
                ...$response->toArray(),
                'thread_ulid' => $thread->ulid,
                'run_ulid' => $run->ulid,
                'user_message_id' => $run->user_message_id,
                'assistant_message_id' => $assistant->id,
                'execution' => $fallbackExecution,
            ]]);
        } catch (InvalidArgumentException $e) {
            $persistence->failRun($run, 'confirmed_tool_unavailable', $e->getMessage());

            return new JsonResponse(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $persistence->failRun($run, 'error', $e->getMessage());
            throw $e;
        }
    }

    public function testArticles(Request $request, SiteDirectory $sites, AgentTestArticleCatalogService $articles): JsonResponse
    {
        $user = $request->user();
        if ($user === null || (int) $user->id <= 0) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;
        $siteId = (int) $request->query('site_id', 0);
        if ($siteId <= 0 || ! $sites->isSiteVisible($siteId, $userId)) {
            return new JsonResponse(['message' => 'Site is invalid or inaccessible.'], 403);
        }
        $ownerId = (method_exists($user, 'accountOwnerId') ? $user->accountOwnerId() : null) ?? $userId;

        return new JsonResponse(['data' => ['articles' => $articles->search(
            (int) $ownerId,
            $siteId,
            (string) $request->query('q', ''),
        )]]);
    }

    public function testRun(
        Request $request,
        AgentTestExecutionService $tests,
        SiteDirectory $sites,
        AgentThreadRepository $threads,
        AgentTurnPersistence $persistence,
    ): JsonResponse {
        $user = $request->user();
        if ($user === null || (int) $user->id <= 0) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;
        $siteId = (int) $request->input('site_id', 0);
        if ($siteId <= 0 || ! $sites->isSiteVisible($siteId, $userId)) {
            return new JsonResponse(['message' => 'Site is invalid or inaccessible.'], 403);
        }
        $app = AgentApp::findByKey((string) $request->input('app_key', 'seo-ops')) ?? AgentApp::findByKey('seo-ops');
        if ($app === null) {
            return new JsonResponse(['message' => 'Agent app not found.'], 422);
        }

        $ownerId = (method_exists($user, 'accountOwnerId') ? $user->accountOwnerId() : null) ?? $userId;
        try {
            $result = $tests->run((int) $ownerId, $siteId, $request->all());
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 422);
        } catch (ModelNotFoundException) {
            return new JsonResponse(['message' => 'Test target not found.'], 404);
        }

        $thread = $threads->createThread($app, 'user', (string) $userId, $userId, (int) $ownerId, 'site', 'site:'.$siteId, 'Test · '.$result['target_label']);
        $input = (string) ($result['context_summary'] ?? 'Agent Test');
        $userMessage = $persistence->persistUserMessage($thread, $input);
        $run = $persistence->startRun($thread, $userMessage, $app->app_key, 'site', 'site:'.$siteId, $userId);
        $assistant = $persistence->completeRun($run, new AgentResponse(
            message: (string) (($result['output'] ?? '') !== '' ? $result['output'] : ($result['error'] ?? $result['status'])),
            blocks: [['type' => 'test_result', 'data' => $result]],
            actions: [],
            sources: [],
        ));
        $threads->touchLastMessage($thread);
        $result['thread_ulid'] = $thread->ulid;
        $result['assistant_message_id'] = $assistant->id;

        return new JsonResponse(['data' => $result]);
    }

    public function testCatalog(Request $request, AgentTestCatalogService $catalog): JsonResponse
    {
        abort_unless($request->user() !== null, 401);

        return new JsonResponse([
            'data' => [
                'targets' => $catalog->forOwner(SeoAccessControl::accountSiteOwnerId()),
            ],
        ]);
    }

    public function welcomeQuestions(Request $request, AgentWelcomeQuestions $welcome): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        return new JsonResponse(['data' => ['modules' => $welcome->forUser($user)]]);
    }

    public function saveWelcomeQuestions(Request $request, AgentWelcomeQuestions $welcome): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $modules = $request->input('modules', []);

        return new JsonResponse([
            'data' => ['modules' => $welcome->save($user, is_array($modules) ? $modules : [])],
        ]);
    }

    public function projects(Request $request, SiteDirectory $sites): JsonResponse
    {
        $user = $request->user();
        $userId = $user ? (int) $user->id : null;
        $items = [[
            'type' => 'global',
            'key' => 'global',
            'label' => 'All Sites',
            'retrieval' => 'unsupported',
        ]];
        foreach ($sites->listActiveSites($userId) as $site) {
            $items[] = [
                'type' => 'site',
                'key' => 'site:'.$site['id'],
                'siteId' => $site['id'],
                'siteRef' => 'site:'.$site['id'],
                'label' => $site['domain'],
                'retrieval' => 'supported',
            ];
        }

        return new JsonResponse([
            'data' => [
                'projects' => $items,
                'addWebsite' => false,
            ],
        ]);
    }

    public function turn(
        Request $request,
        AgentTurnCoordinator $coordinator,
        SiteDirectory $sites,
        AgentThreadRepository $threads,
        AgentTurnPersistence $persistence,
        ?AssumedModelResolver $modelResolver = null,
    ): JsonResponse {
        $user = $request->user();
        if ($user === null || (int) $user->id <= 0) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;

        $payload = $request->all();
        if (! is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid payload.'], 422);
        }
        $scopeRaw = $payload['scope'] ?? ($payload['hostContext']['scope'] ?? null);
        if (! is_array($scopeRaw)) {
            return new JsonResponse(['message' => 'Scope is required.'], 422);
        }

        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            return new JsonResponse(['message' => 'Message is required.'], 422);
        }

        try {
            $scope = AgentProjectScope::fromArray($scopeRaw);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        if ($scope->isSite()) {
            if (! $sites->isSiteVisible($scope->siteId, $userId)) {
                return new JsonResponse(['message' => 'Site is invalid or inaccessible.'], 403);
            }
        }

        $appKey = $payload['hostContext']['appKey'] ?? 'seo-ops';
        $app = AgentApp::findByKey($appKey) ?? AgentApp::findByKey('seo-ops');
        if (!$app) {
            return new JsonResponse(['message' => 'Agent app not found.'], 422);
        }

        $principalType = 'user';
        $principalRef = (string) $userId;
        $ownerId = (method_exists($user, 'accountOwnerId') ? $user->accountOwnerId() : null) ?? $userId;

        $threadUlid = $payload['thread_ulid'] ?? null;
        if ($threadUlid) {
            $thread = $threads->findForPrincipal($threadUlid, $principalType, $principalRef);
            if (!$thread) {
                return new JsonResponse(['message' => 'Thread not found.'], 404);
            }
            if ($thread->status === 'archived') {
                return new JsonResponse(['message' => 'Archived thread cannot receive new messages.'], 422);
            }
        } else {
            $title = $this->makeThreadTitle($message);
            $thread = $threads->createThread(
                $app,
                $principalType,
                $principalRef,
                $userId,
                $ownerId,
                $scope->type,
                $scope->siteRef ?? 'global',
                $title
            );
        }

        $userMessage = $persistence->persistUserMessage($thread, $message);
        $run = $persistence->startRun(
            $thread,
            $userMessage,
            $app->app_key,
            $scope->type,
            $scope->siteRef ?? 'global',
            $userId
        );

        $history = is_array($payload['history'] ?? null) ? $payload['history'] : [];

        $debugMode = ($payload['debug_mode'] ?? false) === true;

        return $this->executeRun(
            $coordinator,
            $run,
            $thread,
            $persistence,
            $threads,
            $debugMode ? ($modelResolver ?? (app()->bound(AssumedModelResolver::class) ? app(AssumedModelResolver::class) : null)) : null,
            $userId,
            $scope,
            $message,
            $history,
            $debugMode,
            ($payload['diagnostics'] ?? false) === true,
        );
    }

    public function modelInput(Request $request, AgentTurnCoordinator $coordinator, SiteDirectory $sites): JsonResponse
    {
        $user = $request->user();
        if ($user === null || (int) $user->id <= 0) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;

        $payload = $request->all();
        if (! is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid payload.'], 422);
        }
        $scopeRaw = $payload['scope'] ?? ($payload['hostContext']['scope'] ?? null);
        if (! is_array($scopeRaw)) {
            return new JsonResponse(['message' => 'Scope is required.'], 422);
        }

        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            return new JsonResponse(['message' => 'Message is required.'], 422);
        }

        try {
            $scope = AgentProjectScope::fromArray($scopeRaw);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        if ($scope->isSite()) {
            if (! $sites->isSiteVisible($scope->siteId, $userId)) {
                return new JsonResponse(['message' => 'Site is invalid or inaccessible.'], 403);
            }
        }

        $history = is_array($payload['history'] ?? null) ? $payload['history'] : [];
        $result = $coordinator->copy($userId, $scope, $message, $history);

        if ($result->confirmationProposal !== null) {
            return new JsonResponse(['data' => [
                'status' => 'awaiting_confirmation',
                'confirmation' => $result->confirmationProposal->clientPayload(),
                'copy' => ['routing' => $result->routingInput->exportText(), 'answer' => null],
            ]]);
        }

        return new JsonResponse([
            'data' => [
                'copy' => [
                    'answer' => $result->answerInput->exportText(),
                    'routing' => $result->routingInput->exportText(),
                ],
                'response' => $result->response->toArray(),
            ],
        ]);
    }

    public function modelDebugApply(
        Request $request,
        AgentTurnCoordinator $coordinator,
        AgentThreadRepository $threads,
        AgentTurnPersistence $persistence,
        AssumedModelResolver $modelResolver,
    ): JsonResponse {
        $user = $request->user();
        if ($user === null || (int) $user->id <= 0) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;

        $payload = $request->all();
        $runUlid = trim((string) ($payload['run_ulid'] ?? ''));
        $manualResult = (string) ($payload['manual_result'] ?? '');
        $useVerified = ($payload['use_verified'] ?? false) === true;
        $recoverStranded = ($payload['recover_stranded'] ?? false) === true;
        if ($runUlid === '' || (! $useVerified && ! $recoverStranded && trim($manualResult) === '')) {
            return new JsonResponse(['message' => 'Run and manual result are required.'], 422);
        }

        $run = AgentRun::where('ulid', $runUlid)
            ->where('user_id', $userId)
            ->where('status', 'awaiting_model')
            ->first();
        if (! $run) {
            return new JsonResponse(['message' => 'Paused run not found.'], 404);
        }

        $checkpoint = (array) $run->retrieval_summary;
        $callKey = (string) ($checkpoint['model_call'] ?? '');
        $state = (array) ($checkpoint['runtime_state'] ?? []);
        $turn = (array) ($state['turn'] ?? []);

        if ($recoverStranded || $useVerified) {
            return $this->completeRecoveredAnswer(
                $run,
                $coordinator,
                $persistence,
                $threads,
                $modelResolver,
                $userId,
                $turn,
                $state,
                '',
                true,
            );
        }

        try {
            $scope = AgentProjectScope::fromArray((array) ($turn['scope'] ?? []));
            $persistence->resumeRun($run);
            $progress = $coordinator->resumeIntercepted(
                $scope,
                (string) ($turn['message'] ?? ''),
                (array) ($turn['history'] ?? []),
                $callKey,
                $manualResult,
                $state,
            );
            $thread = $run->thread()->firstOrFail();

            return $this->respondToProgress(
                $progress,
                $run,
                $thread,
                $persistence,
                $threads,
                $modelResolver,
                $userId,
                $turn,
            );
        } catch (RoutingDecisionRejected $e) {
            $persistence->pauseRun($run, 'decision', $state);
            $thread = $run->thread()->firstOrFail();
            $routingData = (array) ($state['routing_input'] ?? []);
            $routingInput = new PreparedModelInput(
                (string) ($routingData['stage'] ?? 'decision'),
                (array) ($routingData['messages'] ?? []),
            );
            $input = $routingInput->exportText();

            return new JsonResponse([
                'message' => 'Routing result rejected: '.$e->getMessage(),
                'validation_error' => $e->getMessage(),
                'data' => [
                    'status' => 'paused',
                    'run_ulid' => $run->ulid,
                    'thread_ulid' => $thread->ulid,
                    'user_message_id' => $run->user_message_id,
                    'model_call' => [
                        'key' => 'decision',
                        'full_prompt' => $input,
                        'prompt_size' => mb_strlen($input),
                        'assumed_model' => $modelResolver->resolveDecisionModel($userId)->toArray(),
                    ],
                    'error' => $e->getMessage(),
                ],
            ], 422);
        } catch (\Omnichannel\Addons\AgentRuntime\Response\AgentResponseRejected) {
            return $this->completeRecoveredAnswer(
                $run,
                $coordinator,
                $persistence,
                $threads,
                $modelResolver,
                $userId,
                $turn,
                $state,
                $manualResult,
                false,
            );
        } catch (\Throwable $e) {
            $persistence->failRun($run, 'error', $e->getMessage());
            throw $e;
        }
    }

    public function createThread(Request $request, AgentThreadRepository $threads): JsonResponse
    {
        $user = $request->user();
        if ($user === null || (int) $user->id <= 0) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;

        $payload = $request->all();
        $appKey = $payload['appKey'] ?? 'seo-ops';
        $app = AgentApp::findByKey($appKey) ?? AgentApp::findByKey('seo-ops');
        if (!$app) {
            return new JsonResponse(['message' => 'Agent app not found.'], 422);
        }

        $principalType = 'user';
        $principalRef = (string) $userId;
        $ownerId = (method_exists($user, 'accountOwnerId') ? $user->accountOwnerId() : null) ?? $userId;

        $scopeType = $payload['scope_type'] ?? 'global';
        $scopeRef = $payload['scope_ref'] ?? 'global';
        $title = $payload['title'] ?? null;

        $thread = $threads->createThread(
            $app,
            $principalType,
            $principalRef,
            $userId,
            $ownerId,
            $scopeType,
            $scopeRef,
            $title
        );

        return new JsonResponse([
            'data' => [
                'thread_ulid' => $thread->ulid,
                'title' => $thread->title,
            ],
        ]);
    }

    public function listThreads(Request $request, AgentThreadRepository $threads): JsonResponse
    {
        $user = $request->user();
        if ($user === null || (int) $user->id <= 0) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;

        $principalType = 'user';
        $principalRef = (string) $userId;

        $appKey = $request->query('appKey');
        $scopeRef = $request->query('scope_ref');
        $perPage = (int) $request->query('per_page', 20);
        $status = $request->query('status', 'active');

        if ($status === 'archived') {
            $paginator = $threads->listArchivedForPrincipal($principalType, $principalRef, $appKey, $scopeRef, $perPage);
        } else {
            $paginator = $threads->listForPrincipal($principalType, $principalRef, $appKey, $scopeRef, $perPage);
        }

        return new JsonResponse($paginator);
    }

    public function archiveThread(Request $request, string $ulid, AgentThreadRepository $threads): JsonResponse
    {
        $user = $request->user();
        if ($user === null || (int) $user->id <= 0) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;

        $principalType = 'user';
        $principalRef = (string) $userId;

        $thread = $threads->findForPrincipal($ulid, $principalType, $principalRef);
        if (! $thread) {
            return new JsonResponse(['message' => 'Thread not found.'], 404);
        }

        $activeRunsCount = $thread->runs()
            ->whereIn('status', ['running', 'awaiting_model', 'awaiting_confirmation'])
            ->count();
        if ($activeRunsCount > 0) {
            return new JsonResponse(['message' => 'Cannot archive thread with an active run.'], 422);
        }

        $threads->archiveThread($thread);

        return new JsonResponse([
            'data' => [
                'ulid' => $thread->ulid,
                'status' => 'archived',
            ],
        ]);
    }

    public function deleteThread(Request $request, string $ulid, AgentThreadRepository $threads): JsonResponse
    {
        $user = $request->user();
        if ($user === null || (int) $user->id <= 0) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;

        $principalType = 'user';
        $principalRef = (string) $userId;

        $thread = $threads->findForPrincipal($ulid, $principalType, $principalRef);
        if (! $thread) {
            return new JsonResponse(['message' => 'Thread not found.'], 404);
        }

        $activeRunsCount = $thread->runs()
            ->where('status', 'running')
            ->count();
        if ($activeRunsCount > 0) {
            return new JsonResponse(['message' => 'Cannot delete thread with an active run.'], 422);
        }

        $threads->deleteThread($thread);

        return new JsonResponse([
            'data' => [
                'deleted' => true,
                'ulid' => $ulid,
            ],
        ]);
    }

    public function showThread(Request $request, string $ulid, AgentThreadRepository $threads, ?AgentTurnCoordinator $coordinator = null): JsonResponse
    {
        $user = $request->user();
        if ($user === null || (int) $user->id <= 0) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;

        $principalType = 'user';
        $principalRef = (string) $userId;

        $thread = $threads->findForPrincipal($ulid, $principalType, $principalRef);
        if (!$thread) {
            return new JsonResponse(['message' => 'Thread not found.'], 404);
        }

        $thread->load(['messages' => function ($query) {
            $query->orderBy('position', 'asc')->take(50);
        }, 'messages.run:id,user_message_id,retrieval_summary']);

        $data = $thread->toArray();
        $pending = AgentRun::query()
            ->where('thread_id', $thread->id)
            ->where('status', 'awaiting_model')
            ->latest('id')
            ->first();
        if ($pending instanceof AgentRun) {
            $restored = $this->restoredPendingModelCall($pending, $coordinator);
            if ($restored !== null) {
                $data['pending_model_call'] = $restored;
            } else {
                $data['stranded_model_run'] = ['run_ulid' => $pending->ulid];
            }
        }

        return new JsonResponse(['data' => $data]);
    }

    public function threadTurn(
        Request $request,
        string $ulid,
        AgentTurnCoordinator $coordinator,
        SiteDirectory $sites,
        AgentThreadRepository $threads,
        AgentTurnPersistence $persistence,
        ?AssumedModelResolver $modelResolver = null,
    ): JsonResponse {
        $payload = $request->all();
        $payload['thread_ulid'] = $ulid;
        $request->merge($payload);

        return $this->turn($request, $coordinator, $sites, $threads, $persistence, $modelResolver);
    }

    public function rerun(
        Request $request,
        string $ulid,
        int $messageId,
        AgentTurnCoordinator $coordinator,
        SiteDirectory $sites,
        AgentThreadRepository $threads,
        AgentTurnPersistence $persistence,
        AssumedModelResolver $modelResolver,
    ): JsonResponse {
        $user = $request->user();
        if ($user === null || (int) $user->id <= 0) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;
        $thread = $threads->findForPrincipal($ulid, 'user', (string) $userId);
        if (! $thread) {
            return new JsonResponse(['message' => 'Thread not found.'], 404);
        }
        if ($thread->status === 'archived') {
            return new JsonResponse(['message' => 'Archived thread cannot receive new messages.'], 422);
        }
        $userMessage = AgentMessage::where('id', $messageId)
            ->where('thread_id', $thread->id)
            ->where('role', 'user')
            ->first();
        if (! $userMessage) {
            return new JsonResponse(['message' => 'User message not found.'], 404);
        }

        $scope = AgentProjectScope::fromArray([
            'type' => $thread->scope_type,
            'ref' => $thread->scope_ref,
        ]);
        if ($scope->isSite() && ! $sites->isSiteVisible($scope->siteId, $userId)) {
            return new JsonResponse(['message' => 'Site is invalid or inaccessible.'], 403);
        }

        $history = $this->historyBefore($thread->id, $userMessage->position);
        $run = $persistence->startRerun(
            $thread,
            $userMessage,
            (string) $thread->agentApp->app_key,
            $thread->scope_type,
            $thread->scope_ref,
            $userId,
        );

        return $this->executeRun(
            $coordinator,
            $run,
            $thread,
            $persistence,
            $threads,
            $modelResolver,
            $userId,
            $scope,
            $userMessage->content,
            $history,
            ($request->input('debug_mode', false)) === true,
            ($request->input('diagnostics', false)) === true,
        );
    }

    /** @param list<array{role: string, content: string}> $history */
    private function executeRun(
        AgentTurnCoordinator $coordinator,
        AgentRun $run,
        \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread $thread,
        AgentTurnPersistence $persistence,
        AgentThreadRepository $threads,
        ?AssumedModelResolver $modelResolver,
        int $userId,
        AgentProjectScope $scope,
        string $message,
        array $history,
        bool $debugMode,
        bool $diagnostics,
    ): JsonResponse {
        try {
            if ($debugMode) {
                if ($modelResolver === null) {
                    throw new \LogicException('Debug mode requires an assumed model resolver.');
                }
                return $this->respondToProgress(
                    $coordinator->startIntercepted($scope, $message, $history),
                    $run,
                    $thread,
                    $persistence,
                    $threads,
                    $modelResolver,
                    $userId,
                    ['scope' => $scope->toArray(), 'message' => $message, 'history' => $history],
                );
            }

            $result = $coordinator->send($userId, $scope, $message, $history, $diagnostics);
            if ($result->confirmationProposal !== null) {
                $persistence->pauseForConfirmation($run, $result->confirmationProposal);

                return new JsonResponse(['data' => [
                    ...$this->confirmationResponse($result->confirmationProposal, (string) $run->ulid)->toArray(),
                    'status' => 'awaiting_confirmation',
                    'run_ulid' => $run->ulid,
                    'thread_ulid' => $thread->ulid,
                    'user_message_id' => $run->user_message_id,
                    'confirmation' => $result->confirmationProposal->clientPayload(),
                ]]);
            }
            $meta = $result->answerModelCalled ? ['answer_model' => 'called'] : [];
            if ($result->failureCode !== null) {
                $meta['failure_code'] = $result->failureCode;
            }
            if ($result->answerDiagnostics !== null) {
                $persistence->storeAnswerDiagnostics($run, $result->answerDiagnostics);
            }
            $diagnosticsPayload = $result->modelDiagnostics ?? [];
            $publicExecution = $this->clientExecution(
                $result->executionTrace,
                $result->response?->sources ?? [],
                $result->answerModelCalled,
            );
            if (is_array($result->executionTrace)) {
                $diagnosticsPayload['execution'] = $result->executionTrace;
            } elseif ($publicExecution !== null) {
                $diagnosticsPayload['execution'] = $publicExecution;
            }
            if ($diagnosticsPayload !== []) {
                $persistence->storeModelDiagnostics($run, $diagnosticsPayload);
            }
            $assistant = $persistence->completeRun($run, $result->response, $meta);
            $threads->touchLastMessage($thread);
            $data = $result->response->toArray();
            $data['thread_ulid'] = $thread->ulid;
            $data['run_ulid'] = $run->ulid;
            $data['user_message_id'] = $run->user_message_id;
            $data['assistant_message_id'] = $assistant->id;
            if ($result->modelDiagnostics !== null) {
                $data['model_diagnostics'] = $result->modelDiagnostics;
            }
            if ($result->answerDiagnostics !== null) {
                $data['answer_diagnostics'] = $result->answerDiagnostics;
            }
            if ($publicExecution !== null) {
                $data['execution'] = $publicExecution;
            }

            return new JsonResponse(['data' => $data]);
        } catch (\Throwable $e) {
            $persistence->failRun($run, 'error', $e->getMessage());
            throw $e;
        }
    }

    /** @return list<array{role: string, content: string}> */
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

    /** @param array<string, mixed> $turnState */
    private function respondToProgress(
        AgentTurnProgress $progress,
        AgentRun $run,
        \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread $thread,
        AgentTurnPersistence $persistence,
        AgentThreadRepository $threads,
        AssumedModelResolver $modelResolver,
        int $userId,
        array $turnState,
    ): JsonResponse {
        if ($progress->confirmationProposal !== null) {
            $persistence->pauseForConfirmation($run, $progress->confirmationProposal, [
                'debug' => true,
                'turn' => $turnState,
            ]);

            return new JsonResponse(['data' => [
                ...$this->confirmationResponse($progress->confirmationProposal, (string) $run->ulid)->toArray(),
                'status' => 'awaiting_confirmation',
                'run_ulid' => $run->ulid,
                'thread_ulid' => $thread->ulid,
                'user_message_id' => $run->user_message_id,
                'confirmation' => $progress->confirmationProposal->clientPayload(),
            ]]);
        }

        if ($progress->modelCall !== null) {
            $call = $progress->modelCall;
            $state = $call->state;
            $state['turn'] = $turnState;
            $persistence->pauseRun($run, $call->key, $state);
            $input = $call->input->exportText();
            $model = $call->key === 'decision'
                ? $modelResolver->resolveDecisionModel($userId)
                : $modelResolver->resolveAnswerModel($userId);

            return new JsonResponse(['data' => [
                'status' => 'paused',
                'run_ulid' => $run->ulid,
                'thread_ulid' => $thread->ulid,
                'user_message_id' => $run->user_message_id,
                'model_call' => [
                    'key' => $call->key,
                    'full_prompt' => $input,
                    'prompt_size' => mb_strlen($input),
                    'assumed_model' => $model->toArray(),
                    'execution' => $this->pausedExecution($state, $call->key === 'answer' ? 'awaiting' : null),
                ],
            ]]);
        }

        $result = $progress->result;
        if ($result === null) {
            throw new \LogicException('Turn progress has neither a model call nor a result.');
        }

        $meta = $result->answerModelCalled ? ['answer_model' => 'manual'] : [];
        if ($result->failureCode !== null) {
            $meta['failure_code'] = $result->failureCode;
        }
        $assistant = $persistence->completeRun($run, $result->response, $meta);
        $threads->touchLastMessage($thread);

        $data = $result->response->toArray();
        $data['thread_ulid'] = $thread->ulid;
        $data['run_ulid'] = $run->ulid;
        $data['user_message_id'] = $run->user_message_id;
        $data['assistant_message_id'] = $assistant->id;
        $manualExecution = $this->rememberExecution($persistence, $run, $result->executionTrace, $result->response?->sources ?? [], $result->answerModelCalled);
        if ($manualExecution !== null) {
            $data['execution'] = $manualExecution;
        }

        return new JsonResponse(['data' => $data]);
    }

    /**
     * @param  array<string, mixed>  $turn
     * @param  array<string, mixed>  $state
     */
    private function completeRecoveredAnswer(
        AgentRun $run,
        AgentTurnCoordinator $coordinator,
        AgentTurnPersistence $persistence,
        AgentThreadRepository $threads,
        AssumedModelResolver $modelResolver,
        int $userId,
        array $turn,
        array $state,
        string $raw,
        bool $resume,
    ): JsonResponse {
        $trace = is_array($state['execution'] ?? null) ? $state['execution'] : [];
        $trace['answer_status'] = 'rejected';
        $trace['external_model_calls'] = 0;
        $trace['external_model'] = null;
        $response = null;
        if (is_array($state['bundle'] ?? null)) {
            try {
                $bundle = RetrievalBundle::fromArray($state['bundle']);
                $response = $coordinator->recoverRejectedAnswer(
                    $raw,
                    $bundle,
                    (string) ($turn['message'] ?? ''),
                    (string) ($state['selected_response_language'] ?? 'en'),
                );
            } catch (\Throwable) {
                $response = null;
            }
        }
        if (! $response instanceof AgentResponse) {
            $notice = 'The paused model call could not be restored. No new model request was made.';
            $response = new AgentResponse($notice, [['type' => 'warning', 'text' => $notice]], [], []);
        }
        if ($resume) {
            $persistence->resumeRun($run);
        }
        $thread = $run->thread()->firstOrFail();

        return $this->respondToProgress(
            AgentTurnProgress::completed(new AgentTurnResult(
                $response,
                new PreparedModelInput('answer', []),
                null,
                false,
                executionTrace: $trace,
            )),
            $run,
            $thread,
            $persistence,
            $threads,
            $modelResolver,
            $userId,
            $turn,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function restoredPendingModelCall(AgentRun $pending, ?AgentTurnCoordinator $coordinator): ?array
    {
        $summary = is_array($pending->retrieval_summary) ? $pending->retrieval_summary : [];
        $callKey = (string) ($summary['model_call'] ?? '');
        $state = is_array($summary['runtime_state'] ?? null) ? $summary['runtime_state'] : [];
        if (! in_array($callKey, ['decision', 'answer'], true) || $state === []) {
            return null;
        }

        try {
            $turn = is_array($state['turn'] ?? null) ? $state['turn'] : [];
            $message = trim((string) ($turn['message'] ?? ''));
            $history = is_array($turn['history'] ?? null) ? $turn['history'] : [];
            if ($callKey === 'decision') {
                $routingData = is_array($state['routing_input'] ?? null) ? $state['routing_input'] : [];
                $messages = $routingData['messages'] ?? null;
                if (! is_array($messages) || $messages === []) {
                    return null;
                }
                $input = (new PreparedModelInput((string) ($routingData['stage'] ?? 'decision'), $messages))->exportText();
            } else {
                if (! $coordinator instanceof AgentTurnCoordinator || ! is_array($state['bundle'] ?? null) || $message === '') {
                    return null;
                }
                $scope = AgentProjectScope::fromArray(is_array($turn['scope'] ?? null) ? $turn['scope'] : []);
                $input = $coordinator->buildAnswerInput(
                    $scope,
                    $message,
                    $history,
                    RetrievalBundle::fromArray($state['bundle']),
                    (string) ($state['selected_response_template'] ?? ''),
                    (string) ($state['selected_response_language'] ?? 'en'),
                )->exportText();
            }
        } catch (\Throwable) {
            return null;
        }

        if (trim($input) === '') {
            return null;
        }

        return [
            'run_ulid' => $pending->ulid,
            'model_call' => [
                'key' => $callKey,
                'full_prompt' => $input,
                'prompt_size' => mb_strlen($input),
                'execution' => $this->pausedExecution($state, 'awaiting'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $trace
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>|null
     */
    private function clientExecution(?array $trace, array $sources, bool $answerModelCalled): ?array
    {
        $tools = [];
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }
            $name = trim((string) ($source['name'] ?? ''));
            if ($name === '' || $name === 'gsc_fallback_policy') {
                continue;
            }
            $tools[] = $name;
        }
        if ($tools === [] && is_array($trace)) {
            foreach ((array) ($trace['tools'] ?? []) as $tool) {
                if (is_string($tool) && $tool !== '' && $tool !== 'gsc_fallback_policy') {
                    $tools[] = $tool;
                }
            }
        }
        $tools = array_values(array_unique($tools));
        $calls = (int) (is_array($trace) ? ($trace['external_model_calls'] ?? 0) : 0);
        if ($answerModelCalled) {
            $calls = max(1, $calls);
        }
        if ($tools === [] && $calls < 1) {
            return null;
        }

        return [
            'router' => is_array($trace) ? ($trace['router'] ?? null) : null,
            'outcome' => is_array($trace) ? ($trace['outcome'] ?? null) : null,
            'capabilities' => is_array($trace)
                ? array_values(array_filter((array) ($trace['capabilities'] ?? []), 'is_string'))
                : [],
            'tools' => $tools,
            'external_model_calls' => $calls,
            'external_model' => $calls > 0 && is_array($trace) ? ($trace['external_model'] ?? null) : null,
            'answer_status' => is_array($trace) ? ($trace['answer_status'] ?? null) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    private function pausedExecution(array $state, ?string $answerStatus): ?array
    {
        $execution = is_array($state['execution'] ?? null) ? $state['execution'] : null;
        if ($execution === null) {
            return null;
        }
        if ($answerStatus !== null) {
            $execution['answer_status'] = $answerStatus;
        }

        return $execution;
    }

    /**
     * @param  array<string, mixed>|null  $trace
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>|null
     */
    private function rememberExecution(AgentTurnPersistence $persistence, AgentRun $run, ?array $trace, array $sources, bool $answerModelCalled): ?array
    {
        $public = $this->clientExecution($trace, $sources, $answerModelCalled);
        if ($public === null) {
            return null;
        }
        $summary = is_array($run->retrieval_summary) ? $run->retrieval_summary : [];
        $existing = is_array($summary['model_diagnostics'] ?? null) ? $summary['model_diagnostics'] : [];
        if (! isset($existing['execution'])) {
            $existing['execution'] = $public;
            $persistence->storeModelDiagnostics($run, $existing);
        }

        return $public;
    }

    private function makeThreadTitle(string $message): string {
        $title = trim(preg_replace('/\s+/', ' ', $message));
        return mb_substr($title, 0, 80);
    }

    public function draftIntake(Request $request, SiteDirectory $sites, ServiceApiDraftIntakeService $intake): JsonResponse
    {
        $user = $request->user();
        if ($user === null || (int) $user->id <= 0) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;
        $siteId = (int) $request->input('site_id', 0);
        if ($siteId <= 0 || ! $sites->isSiteVisible($siteId, $userId)) {
            return new JsonResponse(['message' => 'Site is invalid or inaccessible.'], 403);
        }

        $rawItems = $request->input('items', []);
        if (! is_array($rawItems) || $rawItems === [] || count($rawItems) > ServiceApiDraftIntakeService::MAX_BATCH) {
            return new JsonResponse(['message' => 'Select between 1 and 100 recommendations.'], 422);
        }

        $items = [];
        foreach (array_values($rawItems) as $row) {
            if (! is_array($row)) {
                return new JsonResponse(['message' => 'Recommendation item is malformed.'], 422);
            }
            $type = trim((string) ($row['type'] ?? ''));
            $articleRef = trim((string) ($row['article_ref'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            $keyword = trim((string) ($row['keyword'] ?? ''));
            $source = is_array($row['source'] ?? null) ? $row['source'] : [];
            if (! in_array($type, ServiceApiDraftIntakeService::ALLOWED_TYPES, true)) {
                return new JsonResponse(['message' => 'Recommendation type is invalid.'], 422);
            }
            if (in_array($type, ['rewrite', 'improve'], true) && preg_match('/^article:\d+$/', $articleRef) !== 1) {
                return new JsonResponse(['message' => 'Existing articles require article_ref.'], 422);
            }
            if ($type === 'new' && $title === '' && $keyword === '') {
                return new JsonResponse(['message' => 'New ideas require a title or keyword.'], 422);
            }
            $sourceType = trim((string) ($source['type'] ?? ''));
            if (! in_array($sourceType, ServiceApiDraftIntakeService::ALLOWED_SOURCE_TYPES, true)) {
                return new JsonResponse(['message' => 'Recommendation source is invalid.'], 422);
            }
            $items[] = [
                'type' => $type,
                'title' => $title,
                'keyword' => $keyword,
                'article_ref' => $articleRef !== '' ? $articleRef : null,
                'source' => [
                    'type' => $sourceType,
                    'ref' => trim((string) ($source['ref'] ?? '')) ?: null,
                    'reason' => trim((string) ($source['reason'] ?? '')) ?: null,
                ],
            ];
        }

        $identity = array_map(static fn (array $item): string => $item['type'].'|'.($item['article_ref'] ?? '').'|'.$item['title'].'|'.$item['keyword'], $items);
        sort($identity);
        $idempotencyKey = 'agent-draft:'.$userId.':'.hash('sha256', implode("\n", $identity));

        try {
            $result = $intake->intake([
                'site_id' => $siteId,
                'items' => $items,
            ], $idempotencyKey);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        $payload = $result->toArray();
        $draftId = preg_match('/^project:(\d+)$/', (string) ($payload['draft_ref'] ?? ''), $match) === 1 ? $match[1] : null;
        $payload['draft_url'] = $draftId !== null ? '/seo/content-projects/'.$draftId : null;
        $payload['complete'] = ((int) ($payload['failed'] ?? 0)) === 0;

        return new JsonResponse(['data' => $payload]);
    }

    private function confirmationResponse(AgentToolConfirmationProposal $proposal, string $runUlid): AgentResponse
    {
        $labels = [];
        foreach ($proposal->toolCapabilities as $capability) {
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

    private function completeDeterministicRun(
        AgentRun $run,
        \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread $thread,
        AgentTurnPersistence $persistence,
        AgentThreadRepository $threads,
        string $message,
    ): JsonResponse {
        $response = new AgentResponse($message, [['type' => 'markdown', 'text' => $message]], [], []);
        $assistant = $persistence->completeRun($run, $response);
        $threads->touchLastMessage($thread);

        return new JsonResponse(['data' => [
            ...$response->toArray(),
            'thread_ulid' => $thread->ulid,
            'run_ulid' => $run->ulid,
            'user_message_id' => $run->user_message_id,
            'assistant_message_id' => $assistant->id,
        ]]);
    }

    private function formatPeriod(string $period): string
    {
        return preg_match('/^(\d{4})-(\d{2})$/', $period, $matches) === 1
            ? $matches[2].'/'.$matches[1]
            : $period;
    }
}
