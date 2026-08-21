<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Middleware;

use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Omnichannel\Addons\Seo\Support\SeoPanelRoutes;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;

/**
 * Filament SEO login route requires {connection_hash}.
 * Never call getLoginUrl() without applying / passing hash.
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
        $panelId = Filament::getCurrentPanel()?->getId();

        // Short Main Service panel — never bounce guests onto a hash login URL.
        if ($panelId === 'seo-main') {
            return SeoPanelRoutes::shortLoginUrl([
                'return_url' => $request->fullUrl(),
            ]);
        }

        $hash = SeoConnectionContext::applyUrlDefaultsFromRequest($request);

        if ($hash !== null) {
            return route('filament.seo.auth.login', ['connection_hash' => $hash]);
        }

        return SeoPanelRoutes::shortLoginUrl();
    }
}
