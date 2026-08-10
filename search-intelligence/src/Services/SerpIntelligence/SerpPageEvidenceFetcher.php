<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

/**
 * Fetch page evidence với SSRF protection — metadata_only mode unit-testable.
 */
final class SerpPageEvidenceFetcher
{
    public const MODE_METADATA_ONLY = 'metadata_only';

    public const MODE_HTTP = 'http';

    /**
     * @return array{
     *   allowed: bool,
     *   error_code: ?string,
     *   normalized_url: ?string,
     *   mode: string,
     *   metadata: array<string, mixed>
     * }
     */
    public function validateUrlForFetch(string $url, ?array $config = null): array
    {
        $mode = (string) ($config['mode'] ?? $this->configString('fetch.mode', self::MODE_METADATA_ONLY));
        $parsed = parse_url(trim($url));

        if (! is_array($parsed)) {
            return $this->blocked('serp.fetch_invalid_scheme', $mode, ['reason' => 'unparseable_url']);
        }

        $scheme = mb_strtolower((string) ($parsed['scheme'] ?? ''), 'UTF-8');
        $blockedSchemes = ['file', 'data', 'javascript', 'ftp', 'gopher'];
        if (in_array($scheme, $blockedSchemes, true)) {
            return $this->blocked('serp.fetch_invalid_scheme', $mode, ['scheme' => $scheme, 'reason' => 'blocked_scheme']);
        }

        $allowedSchemes = $this->configStringList('fetch.allowed_schemes', ['http', 'https']);
        if ($scheme === '' || ! in_array($scheme, $allowedSchemes, true)) {
            return $this->blocked('serp.fetch_invalid_scheme', $mode, ['scheme' => $scheme]);
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return $this->blocked('serp.fetch_invalid_scheme', $mode, ['reason' => 'credential_url']);
        }

        $host = (string) ($parsed['host'] ?? '');
        if ($host === '') {
            return $this->blocked('serp.fetch_invalid_scheme', $mode, ['reason' => 'missing_host']);
        }

        if ($this->isBlockedHost($host)) {
            return $this->blocked('serp.fetch_blocked_private_address', $mode, ['host' => $host]);
        }

        $normalizedUrl = $scheme.'://'.$host;
        $path = (string) ($parsed['path'] ?? '');
        if ($path !== '') {
            $normalizedUrl .= $path;
        }
        if (isset($parsed['query']) && is_string($parsed['query']) && $parsed['query'] !== '') {
            $normalizedUrl .= '?'.$parsed['query'];
        }

        return [
            'allowed' => true,
            'error_code' => null,
            'normalized_url' => $normalizedUrl,
            'mode' => $mode,
            'metadata' => [
                'redirect_limit' => (int) ($config['redirect_limit'] ?? $this->configInt('fetch.redirect_limit', 3)),
                'max_bytes' => (int) ($config['max_bytes'] ?? $this->configInt('fetch.max_bytes', 1_048_576)),
            ],
        ];
    }

    /**
     * @return array{
     *   success: bool,
     *   body: ?string,
     *   error_code: ?string,
     *   metadata: array<string, mixed>
     * }
     */
    public function fetch(string $url, ?array $config = null): array
    {
        $validation = $this->validateUrlForFetch($url, $config);
        if (! $validation['allowed']) {
            return [
                'success' => false,
                'body' => null,
                'error_code' => $validation['error_code'],
                'metadata' => $validation['metadata'],
            ];
        }

        $mode = $validation['mode'];
        if ($mode === self::MODE_METADATA_ONLY) {
            return [
                'success' => true,
                'body' => null,
                'error_code' => null,
                'metadata' => array_merge($validation['metadata'], [
                    'skipped_http' => true,
                    'normalized_url' => $validation['normalized_url'],
                ]),
            ];
        }

        return $this->fetchHttp((string) $validation['normalized_url'], $validation['metadata']);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{success: bool, body: ?string, error_code: ?string, metadata: array<string, mixed>}
     */
    private function fetchHttp(string $url, array $metadata): array
    {
        if (! function_exists('curl_init')) {
            return [
                'success' => false,
                'body' => null,
                'error_code' => 'serp.fetch_unavailable',
                'metadata' => $metadata,
            ];
        }

        $redirectLimit = (int) ($metadata['redirect_limit'] ?? 3);
        $maxBytes = (int) ($metadata['max_bytes'] ?? 1_048_576);
        $redirectCount = 0;
        $currentUrl = $url;

        while (true) {
            $validation = $this->validateUrlForFetch($currentUrl);
            if (! $validation['allowed']) {
                return [
                    'success' => false,
                    'body' => null,
                    'error_code' => $validation['error_code'],
                    'metadata' => array_merge($metadata, ['redirect_count' => $redirectCount]),
                ];
            }

            $body = $this->curlFetch((string) $validation['normalized_url'], $maxBytes);
            if ($body['redirect_url'] !== null) {
                $redirectCount++;
                if ($redirectCount > $redirectLimit) {
                    return [
                        'success' => false,
                        'body' => null,
                        'error_code' => 'serp.fetch_redirect_limit',
                        'metadata' => array_merge($metadata, ['redirect_count' => $redirectCount]),
                    ];
                }
                $currentUrl = $body['redirect_url'];
                continue;
            }

            if ($body['too_large']) {
                return [
                    'success' => false,
                    'body' => null,
                    'error_code' => 'serp.fetch_response_too_large',
                    'metadata' => array_merge($metadata, ['redirect_count' => $redirectCount]),
                ];
            }

            return [
                'success' => $body['success'],
                'body' => $body['content'],
                'error_code' => $body['success'] ? null : 'serp.fetch_failed',
                'metadata' => array_merge($metadata, ['redirect_count' => $redirectCount]),
            ];
        }
    }

    /**
     * @return array{success: bool, content: ?string, redirect_url: ?string, too_large: bool}
     */
    private function curlFetch(string $url, int $maxBytes): array
    {
        $content = '';
        $redirectUrl = null;
        $tooLarge = false;

        $handle = curl_init($url);
        if ($handle === false) {
            return ['success' => false, 'content' => null, 'redirect_url' => null, 'too_large' => false];
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->configInt('fetch.timeout_seconds', 15),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$content, &$tooLarge, $maxBytes): int {
                if ($tooLarge) {
                    return 0;
                }
                $content .= $chunk;
                if (strlen($content) > $maxBytes) {
                    $tooLarge = true;

                    return 0;
                }

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$redirectUrl): int {
                if (preg_match('/^Location:\s*(.+)$/i', trim($header), $matches) === 1) {
                    $redirectUrl = trim($matches[1]);
                }

                return strlen($header);
            },
        ]);

        $ok = curl_exec($handle) !== false;
        curl_close($handle);

        if ($tooLarge) {
            return ['success' => false, 'content' => null, 'redirect_url' => null, 'too_large' => true];
        }

        if ($redirectUrl !== null) {
            return ['success' => true, 'content' => null, 'redirect_url' => $redirectUrl, 'too_large' => false];
        }

        return ['success' => $ok, 'content' => $ok ? $content : null, 'redirect_url' => null, 'too_large' => false];
    }

    private function isBlockedHost(string $host): bool
    {
        $hostLower = mb_strtolower($host, 'UTF-8');
        $blockedHosts = $this->configStringList('fetch.blocked_hosts', [
            'localhost',
            '127.0.0.1',
            '0.0.0.0',
            '::1',
            '169.254.169.254',
        ]);

        if (in_array($hostLower, $blockedHosts, true)) {
            return true;
        }

        if (filter_var($hostLower, FILTER_VALIDATE_IP)) {
            return $this->isPrivateIp($hostLower);
        }

        $resolved = gethostbyname($hostLower);
        if ($resolved !== $hostLower && filter_var($resolved, FILTER_VALIDATE_IP)) {
            return $this->isPrivateIp($resolved);
        }

        return false;
    }

    private function isPrivateIp(string $ip): bool
    {
        if (in_array($ip, ['127.0.0.1', '::1', '0.0.0.0'], true)) {
            return true;
        }

        if (str_starts_with($ip, '169.254.')) {
            return true;
        }

        if (str_starts_with($ip, '10.')) {
            return true;
        }

        if (preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $ip) === 1) {
            return true;
        }

        if (str_starts_with($ip, '192.168.')) {
            return true;
        }

        if (str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd') || str_starts_with($ip, 'fe80:')) {
            return true;
        }

        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{allowed: bool, error_code: ?string, normalized_url: ?string, mode: string, metadata: array<string, mixed>}
     */
    private function blocked(string $code, string $mode, array $metadata): array
    {
        return [
            'allowed' => false,
            'error_code' => $code,
            'normalized_url' => null,
            'mode' => $mode,
            'metadata' => $metadata,
        ];
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

    private function configString(string $key, string $default): string
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return (string) config('seo-content-ai.serp_intelligence.'.$key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    private function configInt(string $key, int $default): int
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return (int) config('seo-content-ai.serp_intelligence.'.$key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
