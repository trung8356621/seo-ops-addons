<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Support;

/**
 * Normalize hrefs before persisting seo_link_maps.target_external_url.
 * Unwraps common click wrappers and strips tracking params so storage stays within column limits.
 */
final class SeoLinkMapExternalUrlNormalizer
{
    public const MAX_LENGTH = 2048;

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
        'msclkid',
        'mc_cid',
        'mc_eid',
        'yclid',
        '_ga',
        'ref',
    ];

    /** @var list<string> */
    private const FACEBOOK_REDIRECT_HOSTS = [
        'l.facebook.com',
        'lm.facebook.com',
        'm.facebook.com',
        'facebook.com',
        'www.facebook.com',
    ];

    public static function forStorage(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return null;
        }

        $url = self::unwrapRedirectWrappers($url);
        $url = self::stripTrackingQueryParams($url);

        if (mb_strlen($url) > self::MAX_LENGTH) {
            $url = mb_substr($url, 0, self::MAX_LENGTH);
        }

        return $url !== '' ? $url : null;
    }

    private static function unwrapRedirectWrappers(string $url): string
    {
        for ($i = 0; $i < 3; $i++) {
            $unwrapped = self::unwrapFacebookLPhp($url);
            if ($unwrapped === $url) {
                break;
            }
            $url = $unwrapped;
        }

        return $url;
    }

    private static function unwrapFacebookLPhp(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || ! in_array($host, self::FACEBOOK_REDIRECT_HOSTS, true)) {
            return $url;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path !== '/l.php' && ! str_ends_with($path, '/l.php')) {
            return $url;
        }

        parse_str((string) ($parts['query'] ?? ''), $params);
        $dest = trim((string) ($params['u'] ?? ''));
        if ($dest === '') {
            return $url;
        }

        $decoded = rawurldecode($dest);
        if (str_contains($decoded, '%3A') || str_contains($decoded, '%2F')) {
            $decoded = rawurldecode($decoded);
        }

        $decoded = trim(html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($decoded === '') {
            return $url;
        }

        if (str_starts_with($decoded, '//')) {
            $decoded = 'https:'.$decoded;
        }

        if (preg_match('#^https?://#i', $decoded) !== 1) {
            return $url;
        }

        return $decoded;
    }

    private static function stripTrackingQueryParams(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['query']) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        parse_str((string) $parts['query'], $params);
        if (! is_array($params) || $params === []) {
            return $url;
        }

        $changed = false;
        foreach (self::TRACKING_QUERY_KEYS as $key) {
            if (array_key_exists($key, $params)) {
                unset($params[$key]);
                $changed = true;
            }
        }

        foreach (array_keys($params) as $key) {
            if (! is_string($key)) {
                continue;
            }
            $lower = strtolower($key);
            if (str_starts_with($lower, 'utm_') || str_starts_with($lower, 'utm-')) {
                unset($params[$key]);
                $changed = true;
            }
        }

        if (! $changed) {
            return $url;
        }

        $scheme = (string) $parts['scheme'];
        $host = (string) $parts['host'];
        $path = (string) ($parts['path'] ?? '');
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $user = (string) ($parts['user'] ?? '');
        $pass = isset($parts['pass']) ? ':'.(string) $parts['pass'] : '';
        $auth = $user !== '' ? $user.$pass.'@' : '';
        $fragment = isset($parts['fragment']) ? '#'.(string) $parts['fragment'] : '';
        $query = $params === [] ? '' : '?'.http_build_query($params);

        return $scheme.'://'.$auth.$host.$port.$path.$query.$fragment;
    }
}
