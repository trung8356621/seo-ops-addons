<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\ServiceApi\Runner;

use App\Api\Middleware\AuthenticateServiceApi;
use App\Api\Services\ServiceApiContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService;

final class RunnerSessionController extends Controller
{
    public function __construct(
        private readonly SessionSyncService $sessions,
    ) {}

    public function sync(Request $request, string $service, string $agentUuid): JsonResponse
    {
        $result = $this->sessions->sync($agentUuid, $request->all(), $this->credentialId($request));

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    private function credentialId(Request $request): ?int
    {
        $ctx = $request->attributes->get(AuthenticateServiceApi::REQUEST_CONTEXT_KEY);

        return $ctx instanceof ServiceApiContext ? $ctx->credentialId() : null;
    }
}
