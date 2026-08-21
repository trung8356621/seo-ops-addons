<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Symfony\Component\HttpFoundation\Response;

final class BootFilamentSeoPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $hash = $request->route('connection_hash');
        $panelId = is_string($hash) && SeoConnectionContext::isValidHashFormat($hash)
            ? 'seo'
            : 'seo-main';

        Filament::setCurrentPanel(Filament::getPanel($panelId));
        Filament::bootCurrentPanel();

        return $next($request);
    }
}
