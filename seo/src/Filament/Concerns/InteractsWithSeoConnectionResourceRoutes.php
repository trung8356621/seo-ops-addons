<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Concerns;

use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Filament\Resources\Resource;

/**
 * @mixin Resource
 */
trait InteractsWithSeoConnectionResourceRoutes
{
    public static function panelId(): string
    {
        // User-facing resource URLs use the short Main panel (/seo/{page}).
        return 'seo-main';
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function getUrl(
        string $name = 'index',
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?\Illuminate\Database\Eloquent\Model $tenant = null,
    ): string {
        $panelId = $panel ?? static::panelId();

        if ($panelId === 'seo-main') {
            return parent::getUrl($name, $parameters, $isAbsolute, $panelId, $tenant);
        }

        return parent::getUrl(
            $name,
            SeoConnectionContext::mergePanelRouteParameters($parameters),
            $isAbsolute,
            $panelId,
            $tenant,
        );
    }
}
