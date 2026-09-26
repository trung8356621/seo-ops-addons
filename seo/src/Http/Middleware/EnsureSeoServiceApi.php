<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Middleware;

use App\Api\Middleware\AuthenticateServiceApi;
use App\Api\Services\ServiceApiContext;
use App\Api\Services\ServiceApiError;
use App\Services\ServiceIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEO MCP HTTP endpoints require the authenticated Service to be SEO.
 * Scope alone (mcp:read) is insufficient across Services.
 */
final class EnsureSeoServiceApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->attributes->get(AuthenticateServiceApi::REQUEST_CONTEXT_KEY);
        if (! $context instanceof ServiceApiContext) {
            $context = app()->bound(ServiceApiContext::class)
                ? app(ServiceApiContext::class)
                : null;
        }

        if (! $context instanceof ServiceApiContext) {
            return ServiceApiError::unauthorized();
        }

        $public = ServiceIdentity::publicSlugForCatalog($context->serviceSlug());
        if ($public !== ServiceIdentity::PUBLIC_SEO) {
            return ServiceApiError::forbidden();
        }

        $routeService = (string) $request->route('service', '');
        if ($routeService !== ''
            && ServiceIdentity::publicSlugForCatalog($routeService) !== ServiceIdentity::PUBLIC_SEO
        ) {
            return ServiceApiError::forbidden();
        }

        return $next($request);
    }
}
