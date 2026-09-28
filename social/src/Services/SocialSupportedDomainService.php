<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Services;

use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use App\Models\WpOption;

final class SocialSupportedDomainService
{
    /** @var list<string>|null */
    private ?array $inMemoryDomains = null;

    /** @var list<string> */
    public const DEFAULT_DOMAINS = [
        'facebook.com',
        'linkedin.com',
        'x.com',
        'twitter.com',
        'pinterest.com',
        'reddit.com',
        'threads.net',
    ];

    public static function withSupportedDomains(array $domains): self
    {
        $service = new self();
        $service->inMemoryDomains = (new self())->normalizeDomains($domains);

        return $service;
    }

    /**
     * @return list<string>
     */
    public function getSupportedDomains(): array
    {
        if ($this->inMemoryDomains !== null) {
            return $this->inMemoryDomains;
        }

        $data = WpOption::get(SeoOverviewSettingsService::OPTION_KEY, []);
        if (! is_array($data)) {
            return self::DEFAULT_DOMAINS;
        }

        $raw = $data[SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS] ?? null;

        return $this->normalizeDomains(is_array($raw) ? $raw : self::DEFAULT_DOMAINS);
    }

    public function normalizeDomain(string $input): ?string
    {
        return \Omnichannel\Addons\SearchFoundation\Support\DomainHostNormalizer::normalizeStrict($input);
    }

    /**
     * @param  iterable<mixed>  $inputs
     * @return list<string>
     */
    public function normalizeDomains(iterable $inputs): array
    {
        return \Omnichannel\Addons\SearchFoundation\Support\DomainHostNormalizer::normalizeStrictList($inputs);
    }

    public function domainsToTextarea(array $domains): string
    {
        return implode("\n", $this->normalizeDomains($domains));
    }

    /**
     * @return list<string>
     */
    public function domainsFromTextarea(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        return $this->normalizeDomains($lines);
    }

    public function resolveHost(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return $this->normalizeDomain($host);
    }

    public function isAllowedUrl(string $url): bool
    {
        $normalized = app(SocialUrlNormalizer::class)->normalize($url);
        if ($normalized === null) {
            return false;
        }

        foreach ($this->getSupportedDomains() as $allowedDomain) {
            if ($this->hostMatchesAllowedDomain($normalized['domain'], $allowedDomain)) {
                return true;
            }
        }

        return false;
    }

    public function hostMatchesAllowedDomain(string $host, string $allowedDomain): bool
    {
        $host = $this->normalizeDomain($host) ?? '';
        $allowedDomain = $this->normalizeDomain($allowedDomain) ?? '';

        if ($host === '' || $allowedDomain === '') {
            return false;
        }

        if ($host === $allowedDomain) {
            return true;
        }

        return str_ends_with($host, '.'.$allowedDomain);
    }

    private function isValidDomainLabel(string $domain): bool
    {
        return preg_match(
            '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/',
            $domain,
        ) === 1;
    }
}
