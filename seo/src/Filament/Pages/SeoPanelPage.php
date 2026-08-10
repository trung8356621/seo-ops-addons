<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages;

use Omnichannel\Addons\Seo\Filament\Concerns\InteractsWithSeoConnectionRoutes;
use Filament\Pages\Page;

abstract class SeoPanelPage extends Page
{
    use InteractsWithSeoConnectionRoutes;

    protected static ?string $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return null;
    }
}
