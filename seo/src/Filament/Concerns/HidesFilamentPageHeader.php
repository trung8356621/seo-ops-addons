<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Concerns;

use Illuminate\Contracts\View\View;

trait HidesFilamentPageHeader
{
    public function getHeader(): ?View
    {
        return null;
    }
}
