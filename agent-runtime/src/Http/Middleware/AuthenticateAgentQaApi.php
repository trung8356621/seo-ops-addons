<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\AgentRuntime\Http\AgentQaContext;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateAgentQaApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('agent-runtime.qa_api.enabled', false) !== true) {
            return new JsonResponse([
                'message' => 'Agent QA API is disabled.',
                'error_code' => 'qa_api_disabled',
            ], 404);
        }

        $configuredKey = config('agent-runtime.qa_api.key');
        if (! is_string($configuredKey) || trim($configuredKey) === '') {
            return new JsonResponse([
                'message' => 'Agent QA API key is not configured.',
                'error_code' => 'qa_api_not_configured',
            ], 503);
        }

        $token = $this->extractBearerToken($request);
        if ($token === null || ! hash_equals(trim($configuredKey), $token)) {
            return new JsonResponse([
                'message' => 'Unauthenticated.',
                'error_code' => 'invalid_api_key',
            ], 401);
        }

        $userId = (int) config('agent-runtime.qa_api.user_id', 0);
        if ($userId <= 0) {
            return new JsonResponse([
                'message' => 'Agent QA API user is not configured.',
                'error_code' => 'qa_api_not_configured',
            ], 503);
        }

        $user = User::query()->find($userId);
        if (! $user instanceof User) {
            return new JsonResponse([
                'message' => 'Mapped QA user not found.',
                'error_code' => 'qa_user_not_found',
            ], 401);
        }

        $allowedSiteIds = AgentQaContext::parseAllowedSiteIds(config('agent-runtime.qa_api.allowed_site_ids'));
        $context = new AgentQaContext((int) $user->id, $allowedSiteIds);

        $request->attributes->set(AgentQaContext::REQUEST_ATTRIBUTE, $context);
        $request->setUserResolver(static fn (): User => $user);

        return $next($request);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches) !== 1) {
            return null;
        }

        $token = trim((string) $matches[1]);

        return $token !== '' ? $token : null;
    }
}
