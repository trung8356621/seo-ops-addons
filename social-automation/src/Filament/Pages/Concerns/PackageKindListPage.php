<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns;

use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageRegistryService;

trait PackageKindListPage
{
    abstract protected static function packageKind(): string;

    /**
     * @return list<array<string, mixed>>
     */
    public function packages(): array
    {
        return app(PackageRegistryService::class)->listByKind(static::packageKind());
    }
}
