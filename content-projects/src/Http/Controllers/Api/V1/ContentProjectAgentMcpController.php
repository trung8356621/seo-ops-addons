<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Http\Controllers\Api\V1;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentScopeEvaluator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentSessionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\ContentProjectMcpServer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\ContentProjectMcpToolCatalog;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ContentProjectAgentMcpController extends Controller
{
    public function __construct(
        private readonly ContentProjectMcpServer $mcpServer,
        private readonly ContentProjectMcpToolCatalog $toolCatalog,
        private readonly ContentProjectAgentGateway $gateway,
        private readonly ContentProjectAgentSessionService $sessions,
        private readonly AgentScopeEvaluator $scopeEvaluator,
    ) {}

    public function tools(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->mcpServer->listTools(),
        ]);
    }

    public function call(Request $request): JsonResponse
    {
        return $this->executeCapability($request);
    }

    public function execute(Request $request): JsonResponse
    {
        return $this->executeCapability($request);
    }

    public function storeSession(Request $request): JsonResponse
    {
        $context = $this->buildContext($request);
        $session = $this->sessions->create($context);

        return response()->json([
            'success' => true,
            'data' => [
                'session_ref' => (string) $session->public_ref,
                'expires_at' => $session->expires_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function touchSession(Request $request, string $sessionRef): JsonResponse
    {
        $session = $this->sessions->findByPublicRef($sessionRef);
        if ($session === null) {
            return response()->json([
                'success' => false,
                'code' => 'agent.session_expired',
                'message' => 'Session not found or expired.',
            ], 404);
        }

        $this->sessions->touch($session);

        return response()->json([
            'success' => true,
            'data' => [
                'session_ref' => (string) $session->public_ref,
                'expires_at' => $session->expires_at?->toIso8601String(),
            ],
        ]);
    }

    private function executeCapability(Request $request): JsonResponse
    {
        try {
            $context = $this->buildContext($request);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'code' => 'agent.context_missing',
                'message' => $e->getMessage(),
            ], 422);
        }

        $capability = (string) ($request->input('capability') ?? $request->input('name') ?? '');
        $input = $request->input('input', $request->input('arguments', []));
        if (! is_array($input)) {
            $input = [];
        }

        if ($capability === '') {
            return response()->json([
                'success' => false,
                'code' => 'agent.invalid_input',
                'message' => 'capability or name is required.',
            ], 422);
        }

        $result = $this->toolCatalog->isPlanTool($capability)
            ? $this->mcpServer->callTool($context, $capability, $input)
            : $this->gateway->execute($context, $capability, $input);

        return response()->json($result->toArray(), $result->success ? 200 : $this->statusFor($result->code));
    }

    private function buildContext(Request $request): AgentExecutionContext
    {
        $user = $request->user();
        $body = $request->all();

        $tenantRef = trim((string) ($body['tenant_ref'] ?? $request->header('X-Agent-Tenant-Ref', '')));
        $siteRef = trim((string) ($body['site_ref'] ?? $request->header('X-Agent-Site-Ref', '')));
        $actorRef = trim((string) ($body['actor_ref'] ?? $request->header('X-Agent-Actor-Ref', '')));
        $requestRef = trim((string) ($body['request_ref'] ?? $request->header('X-Request-Id', '')));

        if ($actorRef === '' && $user !== null) {
            $actorRef = 'agent:user:'.(int) $user->id;
        }

        if ($requestRef === '') {
            $requestRef = (string) Str::uuid();
        }

        if ($tenantRef === '' && $siteRef !== '') {
            $tenantRef = 'tenant:'.$siteRef;
        }

        $scopes = $this->resolveScopes($request);

        $resolvedSiteId = null;
        if ($siteRef !== '') {
            $resolvedSiteId = ContentProjectPublicRef::resolveSiteIdStrict($siteRef);
        }

        return AgentExecutionContext::fromArray([
            'actor_ref' => $actorRef,
            'actor_type' => 'agent',
            'tenant_ref' => $tenantRef,
            'site_ref' => $siteRef,
            'request_ref' => $requestRef,
            'session_ref' => $body['session_ref'] ?? $request->header('X-Agent-Session-Ref'),
            'idempotency_key' => $request->header('Idempotency-Key') ?? ($body['idempotency_key'] ?? null),
            'confirmation_token' => $body['confirmation_token'] ?? null,
            'dry_run' => (bool) ($body['dry_run'] ?? false),
            'locale' => (string) ($body['locale'] ?? 'vi'),
            'timezone' => (string) ($body['timezone'] ?? 'Asia/Ho_Chi_Minh'),
            'resolved_site_id' => $resolvedSiteId,
            'resolved_actor_user_id' => $user !== null ? (int) $user->id : null,
            'scopes' => $scopes,
        ]);
    }

    /**
     * @return list<string>
     */
    private function resolveScopes(Request $request): array
    {
        return $this->scopeEvaluator->resolveForRequest($request);
    }

    private function statusFor(string $code): int
    {
        return match ($code) {
            'agent.permission_denied', 'tenant.access_denied', 'auth.forbidden' => 403,
            'resource.not_found', 'project.not_found', 'items.not_found', 'operation.not_found' => 404,
            'agent.rate_limited', 'quota.exceeded', 'quota.denied' => 429,
            'confirmation.required', 'preview.ready', 'operation.already_processing', 'publishing.already_processing' => 409,
            'lifecycle.invalid_transition', 'validation.failed', 'agent.invalid_input' => 422,
            default => 400,
        };
    }
}
