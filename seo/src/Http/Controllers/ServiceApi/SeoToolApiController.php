<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Controllers\ServiceApi;

use App\Api\Middleware\AuthenticateServiceApi;
use App\Api\Services\ServiceApiContext;
use App\Api\Services\ServiceApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolContext;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolExecutor;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolRegistry;

final class SeoToolApiController
{
    public function __construct(
        private readonly SeoToolRegistry $registry,
        private readonly SeoToolExecutor $executor
    ) {
    }

    /**
     * GET /api/v1/services/{service}/tools
     *
     * List available tools filtered for the authenticated caller's scopes and context.
     * Public discovery does NOT leak PHP classes, internal URLs, or disabled tools.
     */
    public function index(Request $request, string $service): JsonResponse
    {
        $context = $this->resolveContext($request);
        if (!$context instanceof SeoToolContext) {
            return ServiceApiError::unauthorized();
        }

        $tools = $this->registry->listForContext($context);

        return response()->json([
            'data' => [
                'service' => $service,
                'tools' => $tools,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * POST /api/v1/services/{service}/tools/{toolKey}/execute
     *
     * Execute a specific capability tool through fail-closed policy checks.
     */
    public function execute(Request $request, string $service, string $toolKey): JsonResponse
    {
        $headerSiteRef = $request->header('X-Site-Ref');
        $bodySiteRef = $request->input('context.site_ref');
        if (is_string($headerSiteRef) && is_string($bodySiteRef) && trim($headerSiteRef) !== trim($bodySiteRef)) {
            return response()->json(['error' => [
                'code' => 'context_mismatch',
                'message' => 'Header and body site context do not match.',
            ]], 422)->header('Cache-Control', 'no-store');
        }

        $context = $this->resolveContext($request);
        if (!$context instanceof SeoToolContext) {
            return ServiceApiError::unauthorized();
        }

        $payload = $request->all();
        if (!is_array($payload)) {
            return ServiceApiError::validationFailed('Request body must be a JSON object.');
        }

        $allowedEnvelope = ['context', 'input', 'confirmed', 'idempotency_key'];
        foreach (array_keys($payload) as $field) {
            if (!in_array($field, $allowedEnvelope, true)) {
                return ServiceApiError::validationFailed("Unknown request field: {$field}");
            }
        }
        $input = $payload['input'] ?? [];
        if (!is_array($input)) {
            return ServiceApiError::validationFailed('Input must be a JSON object.');
        }
        $confirmed = (bool) ($payload['confirmed'] ?? ($request->header('X-Confirmed') === 'true'));

        $result = $this->executor->execute($toolKey, $context, $input, $confirmed);

        if ($result->isSuccess()) {
            $responseData = [
                'tool' => $toolKey,
                'result' => $result->getData(),
            ];
            if (!empty($result->getMeta())) {
                $responseData['meta'] = $result->getMeta();
            }

            return response()->json([
                'data' => $responseData,
            ], $result->getHttpStatus())->header('Cache-Control', 'no-store');
        }

        $errorPayload = [
            'code' => $result->getErrorCode() ?? 'tool_execution_failed',
            'message' => $result->getErrorMessage() ?? 'Tool execution failed.',
        ];
        if (!empty($result->getMeta())) {
            $errorPayload['meta'] = $result->getMeta();
        }

        return response()->json([
            'error' => $errorPayload,
        ], $result->getHttpStatus())->header('Cache-Control', 'no-store');
    }

    private function resolveContext(Request $request): ?SeoToolContext
    {
        $serviceApiContext = $request->attributes->get(AuthenticateServiceApi::REQUEST_CONTEXT_KEY);
        if (!$serviceApiContext instanceof ServiceApiContext) {
            $serviceApiContext = app()->bound(ServiceApiContext::class)
                ? app(ServiceApiContext::class)
                : null;
        }

        $siteRef = $request->header('X-Site-Ref') ?: $request->input('context.site_ref');
        $siteId = SeoToolContext::parseSiteId(is_string($siteRef) ? $siteRef : null);

        $requestRef = (string) ($request->header('X-Request-Ref') ?: $request->header('X-Request-Id') ?: '');
        $idempotencyKey = (string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key') ?: '');

        if ($serviceApiContext instanceof ServiceApiContext) {
            return SeoToolContext::fromServiceApiContext(
                context: $serviceApiContext,
                siteRef: is_string($siteRef) ? $siteRef : null,
                resolvedSiteId: $siteId,
                requestRef: $requestRef,
                idempotencyKey: $idempotencyKey !== '' ? $idempotencyKey : null
            );
        }

        return null;
    }
}

