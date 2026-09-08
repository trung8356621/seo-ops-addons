<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

use InvalidArgumentException;

/**
 * SSRF guard for Seeding outbound fetches (link preview).
 * Protocol http/https only; blocks localhost / private / reserved IPs after DNS resolve.
 */
final class SeedingOutboundUrlPolicy
{
    public function assertSafeUrl(string $url): void
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException('URL is required.');
        }

        if (strlen($url) > 2048) {
            throw new InvalidArgumentException('URL exceeds maximum length.');
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('URL is invalid.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new InvalidArgumentException('Only http/https URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Credentials in URL userinfo are not allowed.');
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === '') {
            throw new InvalidArgumentException('URL host is empty.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);

            return;
        }

        if (in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            throw new InvalidArgumentException('Localhost URLs are not allowed.');
        }

        $ips = @gethostbynamel($host);
        if ($ips === false || $ips === []) {
            $packed = @dns_get_record($host, DNS_AAAA);
            $aaaa = [];
            if (is_array($packed)) {
                foreach ($packed as $row) {
                    if (is_array($row) && isset($row['ipv6'])) {
                        $aaaa[] = (string) $row['ipv6'];
                    }
                }
            }
            if ($aaaa === []) {
                throw new InvalidArgumentException('Hostname could not be resolved.');
            }
            foreach ($aaaa as $ip) {
                $this->assertPublicIp($ip);
            }

            return;
        }

        foreach ($ips as $ip) {
            $this->assertPublicIp((string) $ip);
        }
    }

    public function assertPublicIp(string $ip): void
    {
        $ip = strtolower(trim($ip));
        if ($ip === '::1' || $ip === '0.0.0.0' || $ip === '::' || $ip === 'localhost') {
            throw new InvalidArgumentException('URL resolves to a private or reserved address.');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false || $this->ipv4IsBlocked($long, $ip)) {
                throw new InvalidArgumentException('URL resolves to a private network address.');
            }

            return;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($this->ipv6IsBlocked($ip)) {
                throw new InvalidArgumentException('URL resolves to a private network address.');
            }

            return;
        }

        throw new InvalidArgumentException('URL resolves to an invalid address.');
    }

    private function ipv4IsBlocked(int $long, string $ip): bool
    {
        $ranges = [
            [ip2long('0.0.0.0'), ip2long('0.255.255.255')],
            [ip2long('10.0.0.0'), ip2long('10.255.255.255')],
            [ip2long('127.0.0.0'), ip2long('127.255.255.255')],
            [ip2long('169.254.0.0'), ip2long('169.254.255.255')],
            [ip2long('172.16.0.0'), ip2long('172.31.255.255')],
            [ip2long('192.168.0.0'), ip2long('192.168.255.255')],
            [ip2long('224.0.0.0'), ip2long('239.255.255.255')],
            [ip2long('240.0.0.0'), ip2long('255.255.255.255')],
            [ip2long('100.64.0.0'), ip2long('100.127.255.255')],
        ];
        foreach ($ranges as [$start, $end]) {
            if ($start !== false && $end !== false && $long >= $start && $long <= $end) {
                return true;
            }
        }

        return $ip === '169.254.169.254';
    }

    private function ipv6IsBlocked(string $ip): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return true;
        }
        $v4mapped = inet_pton('::ffff:0:0');
        if ($v4mapped !== false && strncmp($packed, $v4mapped, 12) === 0) {
            $v4 = inet_ntop(substr($packed, 12));
            if (is_string($v4)) {
                $long = ip2long($v4);

                return $long !== false && $this->ipv4IsBlocked($long, $v4);
            }
        }

        $first = ord($packed[0]);
        $second = ord($packed[1]);

        return $ip === '::1'
            || $first === 0xfc || $first === 0xfd
            || ($first === 0xfe && ($second & 0xc0) === 0x80)
            || $first === 0xff;
    }
}
