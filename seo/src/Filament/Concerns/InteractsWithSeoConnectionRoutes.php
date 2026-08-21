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
        $panelId = $panel ?? \Filament\Facades\Filament::getCurrentPanel()?->getId();

        // Short Main panel routes have no {connection_hash} parameter.
        if ($panelId === 'seo-main') {
            return parent::getUrl($parameters, $isAbsolute, $panelId, $tenant);
        }

        return parent::getUrl(
            SeoConnectionContext::mergePanelRouteParameters($parameters),
            $isAbsolute,
            $panel,
            $tenant,
        );
    }
}
