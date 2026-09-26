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
 * Shared SEO MCP HTTP transport helpers for permanent + temporary façades.
 */
final class SeoMcpHttpSupport
{
    public function __construct(
        private readonly McpRouterRegistry $routers,
        private readonly McpRouterReader $reader,
        private readonly McpManifestMarkdownPresenter $markdown,
    ) {}

    public function routers(): McpRouterRegistry
    {
        return $this->routers;
    }

    /**
     * @return array<string, mixed>
     */
    public function rootManifest(): array
    {
        return $this->routers->manifest();
    }

    /**
     * @return array<string, mixed>
     */
    public function routerManifest(string $router): array
    {
        $definition = $this->routers->router($router);

        return (new McpManifestBuilder)->resolveRouter($this->routers, $definition);
    }

    /**
     * @param  array<string, mixed>  $parts
     * @return array<string, mixed>
     */
    public function selectiveRead(int $siteId, string $router, array $parts): array
    {
        $readRequest = McpReadRequest::fromArray($siteId, [
            'router' => $router,
            'parts' => $parts,
        ]);

        return $this->reader->read($readRequest);
    }

    public function renderMarkdown(?string $siteRef = null): string
    {
        return $this->markdown->present($this->routers, $siteRef);
    }

    public function siteExists(int $siteId): bool
    {
        if ($siteId <= 0 || ! Schema::hasTable('sites')) {
            return false;
        }

        return Site::query()->whereKey($siteId)->exists();
    }

    public function resolveFormat(Request $request): string|JsonResponse
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

    public function mapException(InvalidArgumentException $e): JsonResponse
    {
        $message = $e->getMessage();
        if (str_contains($message, 'Unknown MCP router')
            || str_contains($message, 'Unknown context slice key')
        ) {
            return ServiceApiError::notFound($message);
        }

        return ServiceApiError::validationFailed($message);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function decorateTemporaryRoot(array $manifest, string $tokenPath, string $siteRef): array
    {
        $manifest['site_ref'] = $siteRef;
        $manifest['self'] = $tokenPath;
        $routers = is_array($manifest['routers'] ?? null) ? $manifest['routers'] : [];
        foreach ($routers as $i => $router) {
            if (! is_array($router)) {
                continue;
            }
            $key = (string) ($router['key'] ?? '');
            if ($key !== '') {
                $router['href'] = rtrim($tokenPath, '/').'/'.$key;
            }
            $routers[$i] = $router;
        }
        $manifest['routers'] = $routers;

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $routerPayload
     * @return array<string, mixed>
     */
    public function decorateTemporaryRouter(array $routerPayload, string $tokenPath, string $router): array
    {
        $self = rtrim($tokenPath, '/').'/'.$router;
        $routerPayload['self'] = $self;
        $routerPayload['read'] = [
            'method' => 'POST',
            'href' => $self.'/read',
        ];

        return $routerPayload;
    }

    /**
     * @return array<string, string>
     */
    public function noStoreHeaders(): array
    {
        return ['Cache-Control' => 'no-store'];
    }

    public function markdownResponse(string $body): Response
    {
        return response($body, 200, array_merge($this->noStoreHeaders(), [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function jsonData(array $data, bool $noStore = false): JsonResponse
    {
        $response = response()->json(['data' => $data]);
        if ($noStore) {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }

    public function runReadSafely(callable $callback): JsonResponse
    {
        try {
            /** @var array<string, mixed> $result */
            $result = $callback();

            return $this->jsonData($result, true);
        } catch (InvalidArgumentException $e) {
            return $this->mapException($e);
        } catch (Throwable $e) {
            if ($e instanceof InvalidArgumentException) {
                return $this->mapException($e);
            }

            throw $e;
        }
    }
}
