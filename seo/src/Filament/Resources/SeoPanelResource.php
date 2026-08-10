<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Resources;

use Omnichannel\Addons\Seo\Filament\Concerns\InteractsWithSeoConnectionResourceRoutes;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Resources\Resource;

abstract class SeoPanelResource extends Resource
{
    use InteractsWithSeoConnectionResourceRoutes;

    protected static ?string $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    protected static function allowsSeoPanelMutation(): bool
    {
        return SeoAccessControl::canMutateInSeoPanel();
    }

    /**
     * @param  array<int, mixed>  $actions
     * @return array<int, mixed>
     */
    protected static function seoPanelBulkActions(array $actions): array
    {
        return static::allowsSeoPanelMutation() ? $actions : [];
    }
}
