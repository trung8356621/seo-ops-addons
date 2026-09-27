<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Projects\SiteDirectory;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;

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

    public function turn(Request $request, AgentTurnCoordinator $coordinator, SiteDirectory $sites): JsonResponse
    {
        return $this->run($request, $coordinator, $sites, true);
    }

    public function modelInput(Request $request, AgentTurnCoordinator $coordinator, SiteDirectory $sites): JsonResponse
    {
        return $this->run($request, $coordinator, $sites, false);
    }

    private function run(Request $request, AgentTurnCoordinator $coordinator, SiteDirectory $sites, bool $send): JsonResponse
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
        $scopeRaw = $payload['scope'] ?? null;
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
        $result = $send
            ? $coordinator->send($userId, $scope, $message, $history)
            : $coordinator->copy($userId, $scope, $message, $history);

        if ($send) {
            return new JsonResponse(['data' => $result->response->toArray()]);
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
}
