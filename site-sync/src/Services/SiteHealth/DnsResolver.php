<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\SiteHealth;

class DnsResolver
{
    public function resolves(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        return is_array($records) && $records !== [];
    }
}
