<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models\Concerns;

use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

trait UsesSocialAutomationConnection
{
    public function getConnectionName(): ?string
    {
        return SocialAutomationServiceConfig::CONNECTION;
    }
}
