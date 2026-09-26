<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Controllers\ServiceApi;

use App\Api\Mcp\TemporaryMcpAccessManager;
use App\Api\Middleware\AuthenticateServiceApi;
use App\Api\Services\ServiceApiContext;
use App\Api\Services\ServiceApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * Permanent Service API façade for SEO MCP (Bearer service_api_credentials).
 */
final class SeoMcpController
{
    public function __construct(
        private readonly SeoMcpHttpSupport $mcp,
        private readonly TemporaryMcpAccessManager $temporaryAccess,
    ) {}

    public function index(Request $request): JsonResponse|Response
    {
        $format = $this->mcp->resolveFormat($request);
        if ($format instanceof JsonResponse) {
            return $format;
        }

        if ($format === 'markdown') {
            return $this->mcp->markdownResponse($this->mcp->renderMarkdown());
        }

        return $this->mcp->jsonData($this->mcp->rootManifest());
    }

    public function show(string $service, string $router): JsonResponse
    {
        try {
            return $this->mcp->jsonData($this->mcp->routerManifest($router));
        } catch (InvalidArgumentException $e) {
            return $this->mcp->mapException($e);
        }
    }

    public function read(Request $request, string $service, string $router): JsonResponse
    {
        $payload = $request->all();
        if (! is_array($payload)) {
            return ServiceApiError::validationFailed('Request body must be a JSON object.');
        }

        $unknown = array_diff(array_keys($payload), ['site_id', 'parts']);
        if ($unknown !== []) {
            return ServiceApiError::validationFailed(
                'Unknown request fields: '.implode(', ', array_values($unknown))
            );
        }

        if (! array_key_exists('site_id', $payload) || ! is_numeric($payload['site_id'])) {
            return ServiceApiError::validationFailed('site_id is required and must be a positive integer.');
        }

        $siteId = (int) $payload['site_id'];
        if ($siteId <= 0) {
            return ServiceApiError::validationFailed('site_id is required and must be a positive integer.');
        }

        if (! $this->mcp->siteExists($siteId)) {
            return ServiceApiError::validationFailed('Unknown or invalid site_id.');
        }

        if (! array_key_exists('parts', $payload) || ! is_array($payload['parts']) || $payload['parts'] === []) {
            return ServiceApiError::validationFailed('parts must be a non-empty object keyed by part name.');
        }

        return $this->mcp->runReadSafely(
            fn (): array => $this->mcp->selectiveRead($siteId, $router, $payload['parts'])
        );
    }

    public function mintAccess(Request $request, string $service): JsonResponse
    {
        $payload = $request->all();
        if (! is_array($payload)) {
            return ServiceApiError::validationFailed('Request body must be a JSON object.');
        }

        $unknown = array_diff(array_keys($payload), ['site_id']);
        if ($unknown !== []) {
            return ServiceApiError::validationFailed(
                'Unknown request fields: '.implode(', ', array_values($unknown))
            );
        }

        if (! array_key_exists('site_id', $payload) || ! is_numeric($payload['site_id'])) {
            return ServiceApiError::validationFailed('site_id is required and must be a positive integer.');
        }

        $siteId = (int) $payload['site_id'];
        if ($siteId <= 0) {
            return ServiceApiError::validationFailed('site_id is required and must be a positive integer.');
        }

        if (! $this->mcp->siteExists($siteId)) {
            return ServiceApiError::validationFailed('Unknown or invalid site_id.');
        }

        $context = $request->attributes->get(AuthenticateServiceApi::REQUEST_CONTEXT_KEY);
        if (! $context instanceof ServiceApiContext) {
            $context = app()->bound(ServiceApiContext::class)
                ? app(ServiceApiContext::class)
                : null;
        }
        if (! $context instanceof ServiceApiContext) {
            return ServiceApiError::unauthorized();
        }

        try {
            $issued = $this->temporaryAccess->issue(
                $context->service,
                $context->credential,
                $siteId,
            );
        } catch (InvalidArgumentException $e) {
            return ServiceApiError::validationFailed($e->getMessage());
        }

        $accessUrl = url('/api/v1/mcp/access/'.$issued->rawToken);

        return response()->json([
            'data' => [
                'access_url' => $accessUrl,
                'expires_at' => $issued->expiresAt,
                'site_ref' => $issued->siteRef(),
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
