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
    public function projects(SiteDirectory $sites): JsonResponse
    {
        $items = [[
            'type' => 'global',
            'key' => 'global',
            'label' => 'All Sites',
            'retrieval' => 'unsupported',
        ]];
        foreach ($sites->listActiveSites() as $site) {
            $items[] = [
                'type' => 'site',
                'key' => 'site:'.$site['id'],
                'siteId' => $site['id'],
                'siteRef' => 'site:'.$site['id'],
                'label' => $site['domain'],
                'retrieval' => 'supported',
            ];
        }

        return response()->json([
            'data' => [
                'projects' => $items,
                'addWebsite' => false,
            ],
        ]);
    }

    public function turn(Request $request, AgentTurnCoordinator $coordinator): JsonResponse
    {
        return $this->run($request, $coordinator, true);
    }

    public function modelInput(Request $request, AgentTurnCoordinator $coordinator): JsonResponse
    {
        return $this->run($request, $coordinator, false);
    }

    private function run(Request $request, AgentTurnCoordinator $coordinator, bool $send): JsonResponse
    {
        $payload = $request->all();
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid payload.'], 422);
        }
        $scopeRaw = $payload['scope'] ?? null;
        if (! is_array($scopeRaw)) {
            return response()->json(['message' => 'Scope is required.'], 422);
        }

        try {
            $scope = AgentProjectScope::fromArray($scopeRaw);
            $history = is_array($payload['history'] ?? null) ? $payload['history'] : [];
            $result = $send
                ? $coordinator->send((int) $request->user()?->id, $scope, (string) ($payload['message'] ?? ''), $history)
                : $coordinator->copy((int) $request->user()?->id, $scope, (string) ($payload['message'] ?? ''), $history);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result->toArray()]);
    }
}
