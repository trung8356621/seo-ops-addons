<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns;

use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptRegistryUiService;

trait UsesScriptRegistryUi
{
    protected function registryUi(): ScriptRegistryUiService
    {
        return app(ScriptRegistryUiService::class);
    }
}
