<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\SiteHealth;

class DnsResolver
{
    public function resolves(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || dns_get_record($host, DNS_A | DNS_AAAA) !== [];
    }
}
