<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Controllers\ServiceApi;

use App\Api\Mcp\TemporaryMcpAccessContext;
use App\Api\Middleware\ResolveTemporaryMcpAccess;
use App\Api\Services\ServiceApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * Temporary site-bound MCP capability façade (no permanent Bearer key).
 */
final class TemporaryMcpAccessController
{
    public function __construct(
        private readonly SeoMcpHttpSupport $mcp,
    ) {}

    public function index(Request $request, string $token): JsonResponse|Response
    {
        $context = $this->requireContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $format = $this->mcp->resolveFormat($request);
        if ($format instanceof JsonResponse) {
            return $format;
        }

        $tokenPath = '/api/v1/mcp/access/'.$token;

        if ($format === 'markdown') {
            return $this->mcp->markdownResponse(
                $this->mcp->renderMarkdown($context->siteRef())
            );
        }

        $manifest = $this->mcp->decorateTemporaryRoot(
            $this->mcp->rootManifest(),
            $tokenPath,
            $context->siteRef(),
        );

        return $this->mcp->jsonData($manifest, true);
    }

    public function show(Request $request, string $token, string $router): JsonResponse
    {
        $context = $this->requireContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $payload = $this->mcp->decorateTemporaryRouter(
                $this->mcp->routerManifest($router),
                '/api/v1/mcp/access/'.$token,
                $router,
            );

            return $this->mcp->jsonData($payload, true);
        } catch (InvalidArgumentException $e) {
            return $this->mcp->mapException($e);
        }
    }

    public function read(Request $request, string $token, string $router): JsonResponse
    {
        $context = $this->requireContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $payload = $request->all();
        if (! is_array($payload)) {
            return ServiceApiError::validationFailed('Request body must be a JSON object.');
        }

        $unknown = array_diff(array_keys($payload), ['parts']);
        if ($unknown !== []) {
            return ServiceApiError::validationFailed(
                'Unknown request fields: '.implode(', ', array_values($unknown))
            );
        }

        if (! array_key_exists('parts', $payload) || ! is_array($payload['parts']) || $payload['parts'] === []) {
            return ServiceApiError::validationFailed('parts must be a non-empty object keyed by part name.');
        }

        $siteId = $context->siteId;

        return $this->mcp->runReadSafely(
            fn (): array => $this->mcp->selectiveRead($siteId, $router, $payload['parts'])
        );
    }

    private function requireContext(Request $request): TemporaryMcpAccessContext|JsonResponse
    {
        $context = $request->attributes->get(ResolveTemporaryMcpAccess::REQUEST_CONTEXT_KEY);
        if (! $context instanceof TemporaryMcpAccessContext) {
            $context = app()->bound(TemporaryMcpAccessContext::class)
                ? app(TemporaryMcpAccessContext::class)
                : null;
        }

        if (! $context instanceof TemporaryMcpAccessContext) {
            return ServiceApiError::json(
                ServiceApiError::TEMPORARY_ACCESS_INVALID,
                'Temporary MCP access is invalid or expired.',
                401,
            );
        }

        return $context;
    }
}
