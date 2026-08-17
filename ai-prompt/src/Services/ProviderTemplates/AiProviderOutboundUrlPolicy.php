<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ProviderTemplates;

use Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException;

/**
 * SSRF policy for provider template outbound URLs.
 * Resolves DNS and checks the resulting IPs — hostname regex is not sufficient.
 */
final class AiProviderOutboundUrlPolicy
{
    public function assertSafeUrl(string $url, bool $allowHttpForLocal = false): void
    {
        $url = trim($url);
        if ($url === '') {
            throw AiProviderTemplateException::rejected('base_url is required.');
        }

        if (strlen($url) > AiProviderTemplateLimits::MAX_STRING_LENGTH) {
            throw AiProviderTemplateException::rejected('URL exceeds maximum length.');
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw AiProviderTemplateException::rejected('URL is invalid.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $httpsOnly = ! $allowHttpForLocal || ! $this->localHttpAllowed();
        if ($scheme === 'http' && $httpsOnly) {
            throw AiProviderTemplateException::rejected('Only https URLs are allowed.');
        }
        if ($scheme !== 'https' && $scheme !== 'http') {
            throw AiProviderTemplateException::rejected('Unsupported URL scheme.');
        }
        if ($scheme === 'http' && $httpsOnly === false && ! in_array(strtolower((string) $parts['host']), ['localhost', '127.0.0.1'], true)) {
            throw AiProviderTemplateException::rejected('HTTP is only allowed for local development hosts.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw AiProviderTemplateException::rejected('Credentials in URL userinfo are not allowed.');
        }

        $host = strtolower((string) $parts['host']);
        $host = trim($host, '[]');
        if ($host === '') {
            throw AiProviderTemplateException::rejected('URL host is empty.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);

            return;
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
                throw AiProviderTemplateException::rejected('base_url hostname could not be resolved.');
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
            throw AiProviderTemplateException::rejected('base_url resolves to a private or reserved address.');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                throw AiProviderTemplateException::rejected('base_url resolves to an invalid address.');
            }
            if ($this->ipv4IsBlocked($long, $ip)) {
                throw AiProviderTemplateException::rejected('base_url resolves to a private network address.');
            }

            return;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($this->ipv6IsBlocked($ip)) {
                throw AiProviderTemplateException::rejected('base_url resolves to a private network address.');
            }

            return;
        }

        throw AiProviderTemplateException::rejected('base_url resolves to an invalid address.');
    }

    public function assertRelativePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw AiProviderTemplateException::rejected('Endpoint path is required.');
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            throw AiProviderTemplateException::rejected('Endpoint paths must be relative to base_url.');
        }
        $normalized = '/'.ltrim(str_replace('\\', '/', $path), '/');
        $parts = [];
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw AiProviderTemplateException::rejected('Endpoint path traversal is not allowed.');
            }
            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
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

    private function localHttpAllowed(): bool
    {
        $env = function_exists('app') ? (string) app()->environment() : 'production';

        return in_array($env, ['local', 'testing'], true);
    }
}
