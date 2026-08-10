<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Middleware;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SeoPlannerPermissionMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->requiresPlannerAccess($request)) {
            return $next($request);
        }

        $role = SeoAccessControl::effectiveRole();

        if (in_array($role, [SeoAccessControl::ROLE_PLANNER, SeoAccessControl::ROLE_MANAGER], true)) {
            return $next($request);
        }

        abort(403, 'Bạn không có quyền truy cập khu vực Keywords / Performance Hub.');
    }

    private function requiresPlannerAccess(Request $request): bool
    {
        if ($request->routeIs([
            'filament.seo.resources.keywords.*',
            'filament.seo.pages.performance-hub',
            'filament.seo.pages.ai-keyword-discovery',
            'seo.keywords.*',
            'seo.performance.*',
        ])) {
            return true;
        }

        return $request->is(
            'seo/*/keywords',
            'seo/*/keywords/*',
            'seo/*/performance-hub',
            'seo/*/performance-hub/*',
        );
    }
}
