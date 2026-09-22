<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Middleware;

use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;

/**
 * SEO panel auth — guests go to canonical /login (no panel-owned login).
 */
final class SeoAuthenticate extends FilamentAuthenticate
{
    /**
     * @param  array<string>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        SeoConnectionContext::applyUrlDefaultsFromRequest($request);

        parent::authenticate($request, $guards);
    }

    protected function redirectTo($request): ?string
    {
        return route('login');
    }
}
