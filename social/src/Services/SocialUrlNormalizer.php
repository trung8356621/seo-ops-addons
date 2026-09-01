<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Services;

/**
 * Canonical URL normalizer for social evidence (manual + API + future RPA).
 *
 * @phpstan-type NormalizedSocialUrl array{url: string, url_hash: string, domain: string}
 */
final class SocialUrlNormalizer
{
    public function __construct(
        private readonly SocialSupportedDomainService $domainService,
    ) {}

    /**
     * @return NormalizedSocialUrl|null
     */
    public function normalize(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || filter_var($raw, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($raw);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '') {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port === 80 && $scheme === 'http') {
            $port = null;
        }
        if ($port === 443 && $scheme === 'https') {
            $port = null;
        }

        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        $canonical = $scheme.'://'.$host
            .($port !== null ? ':'.$port : '')
            .$path
            .$query;

        $domain = $this->domainService->normalizeDomain($host);
        if ($domain === null) {
            return null;
        }

        return [
            'url' => $canonical,
            'url_hash' => hash('sha256', $canonical),
            'domain' => $domain,
        ];
    }
}
