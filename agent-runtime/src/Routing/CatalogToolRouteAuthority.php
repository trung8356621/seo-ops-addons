<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;

/**
 * Catalog authority. A semantic score never authorizes a missing or unavailable capability.
 */
final class CatalogToolRouteAuthority
{
    public function accepts(string $capabilityKey): bool
    {
        $known = AgentCapabilityCatalog::all();
        if (! isset($known[$capabilityKey])) {
            return false;
        }

        return AgentCapabilityCatalog::isSelectable($capabilityKey)
            && AgentCapabilityCatalog::isAvailable($capabilityKey);
    }
}
