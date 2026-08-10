<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

/**
 * URL normalization cho overlap, domain classifier, own-domain detector.
 */
final class SerpUrlNormalizationService
{
    /** @var list<string> */
    private const DEFAULT_TRACKING_PREFIXES = ['utm_', 'utm-'];

    /** @var list<string> */
    private const DEFAULT_TRACKING_EXACT = ['gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', 'yclid', '_ga', 'ref'];

    /**
     * @return array{url: string, normalized_url: string, domain: string, normalized_domain: string}
     */
    public function normalize(string $url): array
    {
        $original = trim($url);
        if ($original === '') {
            return [
                'url' => '',
                'normalized_url' => '',
                'domain' => '',
                'normalized_domain' => '',
            ];
        }

        $parsed = $this->parseUrlSafe($original);
        if ($parsed === null) {
            return [
                'url' => $original,
                'normalized_url' => mb_strtolower($original, 'UTF-8'),
                'domain' => '',
                'normalized_domain' => '',
            ];
        }

        $scheme = mb_strtolower((string) ($parsed['scheme'] ?? 'https'), 'UTF-8');
        $host = $this->normalizeHost((string) ($parsed['host'] ?? ''));
        $path = $this->normalizePath((string) ($parsed['path'] ?? '/'));
        $query = $this->normalizeQueryString((string) ($parsed['query'] ?? ''));

        $normalizedUrl = $scheme.'://'.$host.$path;
        if ($query !== '') {
            $normalizedUrl .= '?'.$query;
        }

        return [
            'url' => $original,
            'normalized_url' => $normalizedUrl,
            'domain' => $host,
            'normalized_domain' => $host,
        ];
    }

    public function normalizeHost(string $host): string
    {
        $host = trim(mb_strtolower($host, 'UTF-8'));
        if ($host === '') {
            return '';
        }

        if (str_starts_with($host, 'www.')) {
            $host = mb_substr($host, 4);
        }

        $host = $this->stripDefaultPort($host);

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = mb_strtolower($ascii, 'UTF-8');
            }
        }

        return rtrim($host, '.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseUrlSafe(string $url): ?array
    {
        if (! str_contains($url, '://')) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        unset($parts['fragment']);

        return $parts;
    }

    private function normalizePath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        $path = '/'.trim($path, '/');
        if ($this->configBool('url.trailing_slash', false)) {
            return rtrim($path, '/').'/';
        }

        return rtrim($path, '/') ?: '/';
    }

    private function normalizeQueryString(string $query): string
    {
        if ($query === '') {
            return '';
        }

        parse_str($query, $params);
        if (! is_array($params) || $params === []) {
            return '';
        }

        $filtered = [];
        foreach ($params as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if ($this->isTrackingParam($key)) {
                continue;
            }
            $filtered[$key] = $value;
        }

        if ($filtered === []) {
            return '';
        }

        ksort($filtered);

        return http_build_query($filtered, '', '&', PHP_QUERY_RFC3986);
    }

    private function isTrackingParam(string $key): bool
    {
        $lower = mb_strtolower($key, 'UTF-8');
        foreach ($this->trackingExactParams() as $exact) {
            if ($lower === $exact) {
                return true;
            }
        }

        foreach ($this->trackingPrefixes() as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function trackingExactParams(): array
    {
        return $this->configStringList('url.tracking_exact_params', self::DEFAULT_TRACKING_EXACT);
    }

    /** @return list<string> */
    private function trackingPrefixes(): array
    {
        return $this->configStringList('url.tracking_param_prefixes', self::DEFAULT_TRACKING_PREFIXES);
    }

    private function stripDefaultPort(string $host): string
    {
        if (str_ends_with($host, ':443') || str_ends_with($host, ':80')) {
            return preg_replace('/:(443|80)$/', '', $host) ?? $host;
        }

        return $host;
    }

    /** @return list<string> */
    private function configStringList(string $key, array $default): array
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            $value = config('seo-content-ai.serp_intelligence.'.$key, $default);

            return is_array($value) ? array_values(array_map('strval', $value)) : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    private function configBool(string $key, bool $default): bool
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return (bool) config('seo-content-ai.serp_intelligence.'.$key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
