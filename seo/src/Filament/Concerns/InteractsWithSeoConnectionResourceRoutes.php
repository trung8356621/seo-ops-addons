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
        return 'seo';
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
        return parent::getUrl(
            $name,
            SeoConnectionContext::mergePanelRouteParameters($parameters),
            $isAbsolute,
            $panel ?? static::panelId(),
            $tenant,
        );
    }
}
