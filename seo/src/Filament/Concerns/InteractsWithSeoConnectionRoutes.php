<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Concerns;

use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Filament\Pages\Page;

/**
 * @mixin Page
 */
trait InteractsWithSeoConnectionRoutes
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?\Illuminate\Database\Eloquent\Model $tenant = null,
    ): string {
        // User-facing defaults to short Main (/seo/...). Pass panel: 'seo' only when hash URL is required.
        $panelId = $panel ?? 'seo-main';

        if ($panelId === 'seo-main') {
            return parent::getUrl($parameters, $isAbsolute, $panelId, $tenant);
        }

        return parent::getUrl(
            SeoConnectionContext::mergePanelRouteParameters($parameters),
            $isAbsolute,
            $panelId,
            $tenant,
        );
    }
}
