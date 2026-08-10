<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Chuẩn hóa URL để so sánh self-link / dedupe suggestion.
 */
final class SeoSuggestionUrlNormalizer
{
    /** @var list<string> */
    private const TRACKING_QUERY_KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'utm_id',
        'gclid',
        'fbclid',
        'mc_cid',
        'mc_eid',
        '_ga',
        'ref',
    ];

    /**
     * Key so sánh: scheme-agnostic host + path (+ query định danh, không tracking).
     */
    public static function normalize(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || $url === '/' || $url === '#') {
            return '';
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        if (str_starts_with($url, '/')) {
            return self::normalizePathAndQuery($url);
        }

        $parsed = parse_url($url);
        if (! is_array($parsed)) {
            return strtolower(rtrim($url, '/'));
        }

        $host = strtolower((string) ($parsed['host'] ?? ''));
        if ($host === '') {
            return '';
        }

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $path = self::normalizePathOnly((string) ($parsed['path'] ?? '/'));
        $query = self::normalizeQuery(isset($parsed['query']) ? (string) $parsed['query'] : '');

        return $host.$path.$query;
    }

    public static function isPlaceholder(string $url): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '' || $trimmed === '/' || $trimmed === '#') {
            return true;
        }

        $lower = strtolower($trimmed);
        if (in_array($lower, ['http://', 'https://', 'http:///', 'https:///'], true)) {
            return true;
        }

        if (preg_match('#^https?://$#i', $trimmed) === 1) {
            return true;
        }

        return false;
    }

    /**
     * URL HTTP(S) hợp lệ có hostname, hoặc relative path nội bộ bắt đầu bằng /.
     */
    public static function isParsableTarget(string $url, bool $allowRelative = true): bool
    {
        $url = trim($url);
        if ($url === '' || self::isPlaceholder($url)) {
            return false;
        }

        if ($allowRelative && str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return self::normalizePathOnly($url) !== '';
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && trim($host) !== '';
    }

    public static function host(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '/')) {
            return '';
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return SeoLinkMapLinkTypeClassifier::normalizeDomainHost($host);
    }

    private static function normalizePathAndQuery(string $pathWithOptionalQuery): string
    {
        $path = $pathWithOptionalQuery;
        $query = '';
        if (str_contains($path, '?')) {
            [$path, $queryPart] = explode('?', $path, 2);
            $query = self::normalizeQuery($queryPart);
        }

        return self::normalizePathOnly($path).$query;
    }

    private static function normalizePathOnly(string $path): string
    {
        $path = strtolower(trim($path));
        if ($path === '') {
            return '/';
        }

        $path = rtrim($path, '/') ?: '/';

        return $path;
    }

    private static function normalizeQuery(string $query): string
    {
        $query = trim($query);
        if ($query === '') {
            return '';
        }

        parse_str($query, $params);
        if (! is_array($params) || $params === []) {
            return '';
        }

        foreach (self::TRACKING_QUERY_KEYS as $key) {
            unset($params[$key]);
        }

        if ($params === []) {
            return '';
        }

        ksort($params);

        return '?'.http_build_query($params);
    }
}
