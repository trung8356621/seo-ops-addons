<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Middleware;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMainRole
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! SeoAccessControl::canAccessSeoPanel($request->user())) {
            abort(403, 'Bạn không có quyền truy cập');
        }

        return $next($request);
    }
}
