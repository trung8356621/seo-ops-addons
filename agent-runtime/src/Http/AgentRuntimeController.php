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
        $ownerId = isset($user->parent_id) ? (int) $user->parent_id : $userId;

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
        
        try {
            $result = $coordinator->send($userId, $scope, $message, $history);
            
            $meta = [];
            if ($result->answerModelCalled) {
                $meta['answer_model'] = 'called';
            }
            // Extract failure_code from any warning block (not positional index)
            foreach ($result->response->blocks as $block) {
                if (isset($block['type'], $block['text']) && $block['type'] === 'warning') {
                    $meta['failure_code'] = $block['text'];
                    break;
                }
            }

            $persistence->completeRun($run, $result->response, $meta);
            $threads->touchLastMessage($thread);

            $data = $result->response->toArray();
            $data['thread_ulid'] = $thread->ulid;
            
            return new JsonResponse(['data' => $data]);
        } catch (\Throwable $e) {
            $persistence->failRun($run, 'error', $e->getMessage());
            throw $e;
        }
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
        $ownerId = isset($user->parent_id) ? (int) $user->parent_id : $userId;

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
        }]);

        return new JsonResponse(['data' => $thread]);
    }

    public function threadTurn(Request $request, string $ulid, AgentTurnCoordinator $coordinator, SiteDirectory $sites, AgentThreadRepository $threads, AgentTurnPersistence $persistence): JsonResponse
    {
        $payload = $request->all();
        $payload['thread_ulid'] = $ulid;
        $request->merge($payload);

        return $this->turn($request, $coordinator, $sites, $threads, $persistence);
    }

    private function makeThreadTitle(string $message): string {
        $title = trim(preg_replace('/\s+/', ' ', $message));
        return mb_substr($title, 0, 80);
    }
}
