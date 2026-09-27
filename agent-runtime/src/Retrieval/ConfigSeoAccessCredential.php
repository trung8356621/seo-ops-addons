<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

final class ConfigSeoAccessCredential implements SeoAccessCredential
{
    public function bearer(): ?string
    {
        $value = config('agent-runtime.seo_access_bearer');
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
