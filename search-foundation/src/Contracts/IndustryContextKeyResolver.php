<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Contracts;

/**
 * Site → active Industry Context key. Site persistence stays in the client.
 */
interface IndustryContextKeyResolver
{
    public function keyForSite(int $siteId): ?string;
}
