<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\LinkIntelligence;

/**
 * Basic, safe URL normalization for Link Library (no network / redirect resolution).
 *
 * @phpstan-type NormalizedUrl array{original_url: string, normalized_url: string, domain: string}
 */
final class UrlNormalizer
{
    /**
     * @return NormalizedUrl|null
     */
    public function normalize(string $raw): ?array
    {
        $original = trim($raw);
        if ($original === '') {
            return null;
        }

        if (filter_var($original, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($original);
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
        if ($path === '') {
            $path = '/';
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        // Fragment intentionally dropped for safe dedupe; original_url is preserved separately.
        $normalized = $scheme.'://'.$host
            .($port !== null ? ':'.$port : '')
            .$path
            .$query;

        return [
            'original_url' => $original,
            'normalized_url' => $normalized,
            'domain' => $this->registrableDomain($host),
        ];
    }

    public function isAllowedHttpUrl(string $raw): bool
    {
        return $this->normalize($raw) !== null;
    }

    private function registrableDomain(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            return substr($host, 4);
        }

        return $host;
    }
}
