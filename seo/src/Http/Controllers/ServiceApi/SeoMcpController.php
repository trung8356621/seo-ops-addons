<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Controllers\ServiceApi;

use App\Api\Services\ServiceApiError;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Omnichannel\Addons\Seo\Services\Mcp\Manifest\McpManifestBuilder;
use Omnichannel\Addons\Seo\Services\Mcp\Manifest\McpManifestMarkdownPresenter;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpReadRequest;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterReader;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterRegistry;
use Throwable;

/**
 * HTTP adapter for SEO MCP Router — transport only.
 * Delegates discovery/read to McpRouterRegistry / McpRouterReader / ContextRegistry.
 */
final class SeoMcpController
{
    public function __construct(
        private readonly McpRouterRegistry $routers,
        private readonly McpRouterReader $reader,
        private readonly McpManifestMarkdownPresenter $markdown,
    ) {}

    public function index(Request $request): JsonResponse|Response
    {
        $format = $this->resolveFormat($request);
        if ($format instanceof JsonResponse) {
            return $format;
        }

        $manifest = $this->routers->manifest();

        if ($format === 'markdown') {
            return response($this->markdown->present($this->routers), 200, [
                'Content-Type' => 'text/markdown; charset=UTF-8',
            ]);
        }

        return response()->json(['data' => $manifest]);
    }

    public function show(string $service, string $router): JsonResponse
    {
        try {
            $definition = $this->routers->router($router);
        } catch (InvalidArgumentException $e) {
            return $this->mapException($e);
        }

        $builder = new McpManifestBuilder;
        $payload = $builder->resolveRouter($this->routers, $definition);

        return response()->json(['data' => $payload]);
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

        if (! $this->siteExists($siteId)) {
            return ServiceApiError::validationFailed('Unknown or invalid site_id.');
        }

        if (! array_key_exists('parts', $payload) || ! is_array($payload['parts']) || $payload['parts'] === []) {
            return ServiceApiError::validationFailed('parts must be a non-empty object keyed by part name.');
        }

        try {
            $readRequest = McpReadRequest::fromArray($siteId, [
                'router' => $router,
                'parts' => $payload['parts'],
            ]);
            $result = $this->reader->read($readRequest);
        } catch (InvalidArgumentException $e) {
            return $this->mapException($e);
        } catch (Throwable $e) {
            if ($e instanceof InvalidArgumentException) {
                return $this->mapException($e);
            }

            throw $e;
        }

        return response()->json(['data' => $result]);
    }

    private function siteExists(int $siteId): bool
    {
        if (! Schema::hasTable('sites')) {
            return false;
        }

        return Site::query()->whereKey($siteId)->exists();
    }

    private function resolveFormat(Request $request): string|JsonResponse
    {
        $format = $request->query('format');
        if ($format === null || $format === '') {
            $accept = strtolower((string) $request->header('Accept', ''));
            if (str_contains($accept, 'text/markdown')) {
                return 'markdown';
            }

            return 'json';
        }

        if (! is_string($format)) {
            return ServiceApiError::validationFailed('Invalid format.');
        }

        $format = strtolower(trim($format));
        if (! in_array($format, ['json', 'markdown'], true)) {
            return ServiceApiError::validationFailed('Invalid format. Allowed: json, markdown.');
        }

        return $format;
    }

    private function mapException(InvalidArgumentException $e): JsonResponse
    {
        $message = $e->getMessage();
        if (str_contains($message, 'Unknown MCP router')
            || str_contains($message, 'Unknown context slice key')
        ) {
            return ServiceApiError::notFound($message);
        }

        return ServiceApiError::validationFailed($message);
    }
}
