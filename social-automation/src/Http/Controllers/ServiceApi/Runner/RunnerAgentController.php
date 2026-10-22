<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\ServiceApi\Runner;

use App\Api\Middleware\AuthenticateServiceApi;
use App\Api\Services\ServiceApiContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Registry\AgentRegistryService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationApiStatus;

final class RunnerAgentController extends Controller
{
    public function __construct(
        private readonly AgentRegistryService $agents,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $result = $this->agents->register($request->all(), $this->credentialId($request));

        return response()->json($result, SocialAutomationApiStatus::fromResult(
            (bool) ($result['ok'] ?? false),
            $result['errors'] ?? [],
            successStatus: 200
        ));
    }

    public function heartbeat(Request $request, string $service, string $agentUuid): JsonResponse
    {
        $result = $this->agents->heartbeat($agentUuid, $request->all(), $this->credentialId($request));

        return response()->json($result, SocialAutomationApiStatus::fromResult(
            (bool) ($result['ok'] ?? false),
            $result['errors'] ?? []
        ));
    }

    private function credentialId(Request $request): ?int
    {
        $ctx = $request->attributes->get(AuthenticateServiceApi::REQUEST_CONTEXT_KEY);

        return $ctx instanceof ServiceApiContext ? $ctx->credentialId() : null;
    }
}
