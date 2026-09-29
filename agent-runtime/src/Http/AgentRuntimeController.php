<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Projects\SiteDirectory;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository;
use Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentMessage;
use Omnichannel\Addons\AgentRuntime\Model\AssumedModelResolver;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnProgress;

final class AgentRuntimeController
{
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

    public function turn(Request $request, AgentTurnCoordinator $coordinator, SiteDirectory $sites, AgentThreadRepository $threads, AgentTurnPersistence $persistence): JsonResponse
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
            $debugMode ? app(AssumedModelResolver::class) : null,
            $userId,
            $scope,
            $message,
            $history,
            $debugMode,
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
        ?AssumedModelResolver $modelResolver,
    ): JsonResponse {
        $user = $request->user();
        if ($user === null || (int) $user->id <= 0) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $userId = (int) $user->id;

        $payload = $request->all();
        $runUlid = trim((string) ($payload['run_ulid'] ?? ''));
        $manualResult = (string) ($payload['manual_result'] ?? '');
        if ($runUlid === '' || trim($manualResult) === '') {
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

        $paginator = $threads->listForPrincipal($principalType, $principalRef, $appKey, $scopeRef, $perPage);

        return new JsonResponse($paginator);
    }

    public function showThread(Request $request, string $ulid, AgentThreadRepository $threads): JsonResponse
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
        }, 'messages.run:id,user_message_id']);

        return new JsonResponse(['data' => $thread]);
    }

    public function threadTurn(Request $request, string $ulid, AgentTurnCoordinator $coordinator, SiteDirectory $sites, AgentThreadRepository $threads, AgentTurnPersistence $persistence): JsonResponse
    {
        $payload = $request->all();
        $payload['thread_ulid'] = $ulid;
        $request->merge($payload);

        return $this->turn($request, $coordinator, $sites, $threads, $persistence);
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
        );
    }

    /** @param list<array{role: string, content: string}> $history */
    private function executeRun(
        AgentTurnCoordinator $coordinator,
        AgentRun $run,
        \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread $thread,
        AgentTurnPersistence $persistence,
        AgentThreadRepository $threads,
        AssumedModelResolver $modelResolver,
        int $userId,
        AgentProjectScope $scope,
        string $message,
        array $history,
        bool $debugMode,
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

            $result = $coordinator->send($userId, $scope, $message, $history);
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

        return new JsonResponse(['data' => $data]);
    }

    private function makeThreadTitle(string $message): string {
        $title = trim(preg_replace('/\s+/', ' ', $message));
        return mb_substr($title, 0, 80);
    }
}
