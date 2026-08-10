<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Http\Middleware;

use Omnichannel\Addons\Seo\Support\SeoDatabaseRequestBootstrap;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetDynamicSeoDatabase
{
    public function __construct(
        private readonly SeoDatabaseRequestBootstrap $requestBootstrap,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->requestBootstrap->shouldBootstrap($request)) {
            $this->requestBootstrap->bootstrap($request);
        }

        return $next($request);
    }
}
