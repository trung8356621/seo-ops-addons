<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Resources\Pages;

use Omnichannel\Addons\Seo\Filament\Concerns\InteractsWithSeoFilamentFormSaveActions;
use Filament\Resources\Pages\EditRecord;

abstract class SeoEditRecord extends EditRecord
{
    use InteractsWithSeoFilamentFormSaveActions;
}
