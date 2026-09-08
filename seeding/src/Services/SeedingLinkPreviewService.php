<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use Omnichannel\Addons\Seeding\Support\SeedingOutboundUrlPolicy;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Fetch Open Graph / page metadata for feed link previews.
 * Failures never throw to callers as hard errors — return fallback payload.
 */
final class SeedingLinkPreviewService
{
    private const TIMEOUT_SECONDS = 5;

    private const MAX_REDIRECTS = 5;

    private const MAX_BODY_BYTES = 512_000;

    public function __construct(
        private readonly SeedingOutboundUrlPolicy $urls = new SeedingOutboundUrlPolicy,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     preview_url: string,
     *     preview_title: string|null,
     *     preview_description: string|null,
     *     preview_image_url: string|null,
     *     preview_domain: string|null,
     *     preview_fetched_at: string,
     *     preview_status: string,
     *     error: string|null
     * }
     */
    public function fetch(string $url): array
    {
        $fetchedAt = now()->toIso8601String();
        $original = trim($url);

        try {
            $this->urls->assertSafeUrl($original);
        } catch (InvalidArgumentException $e) {
            return $this->fallback($original, $fetchedAt, 'blocked', $e->getMessage());
        }

        try {
            $finalUrl = $this->resolveFinalUrl($original);
            $this->urls->assertSafeUrl($finalUrl);

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders([
                    'User-Agent' => 'OmnichannelSeedingPreview/1.0',
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                ])
                ->withOptions(['allow_redirects' => false])
                ->get($finalUrl);

            if (! $response->successful()) {
                return $this->fallback($finalUrl, $fetchedAt, 'http_error', 'HTTP '.$response->status());
            }

            $body = $response->body();
            if (strlen($body) > self::MAX_BODY_BYTES) {
                $body = substr($body, 0, self::MAX_BODY_BYTES);
            }

            $meta = $this->parseHtmlMeta($body, $finalUrl);

            return [
                'ok' => true,
                'preview_url' => $finalUrl,
                'preview_title' => $meta['title'],
                'preview_description' => $meta['description'],
                'preview_image_url' => $meta['image'],
                'preview_domain' => $this->domainOf($finalUrl),
                'preview_fetched_at' => $fetchedAt,
                'preview_status' => 'ok',
                'error' => null,
            ];
        } catch (Throwable $e) {
            return $this->fallback($original, $fetchedAt, 'error', $e->getMessage());
        }
    }

    /**
     * Follow redirects (short links) with per-hop SSRF checks.
     */
    public function resolveFinalUrl(string $url): string
    {
        $current = $url;
        for ($i = 0; $i < self::MAX_REDIRECTS; $i++) {
            $this->urls->assertSafeUrl($current);

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => 'OmnichannelSeedingPreview/1.0'])
                ->withOptions(['allow_redirects' => false])
                ->head($current);

            $status = $response->status();
            if ($status < 300 || $status >= 400) {
                // Some hosts reject HEAD — try GET without following.
                if ($status === 405 || $status === 403 || $status === 0) {
                    $get = Http::timeout(self::TIMEOUT_SECONDS)
                        ->withHeaders(['User-Agent' => 'OmnichannelSeedingPreview/1.0'])
                        ->withOptions(['allow_redirects' => false])
                        ->get($current);
                    $status = $get->status();
                    $location = $get->header('Location');
                } else {
                    return $current;
                }
            } else {
                $location = $response->header('Location');
            }

            if ($status >= 300 && $status < 400 && is_string($location) && $location !== '') {
                $current = $this->absolutizeUrl($current, $location);
                continue;
            }

            return $current;
        }

        return $current;
    }

    /**
     * @return array{title: string|null, description: string|null, image: string|null}
     */
    public function parseHtmlMeta(string $html, string $baseUrl): array
    {
        $title = $this->metaContent($html, 'og:title')
            ?? $this->metaName($html, 'twitter:title')
            ?? $this->htmlTitle($html);

        $description = $this->metaContent($html, 'og:description')
            ?? $this->metaName($html, 'description')
            ?? $this->metaName($html, 'twitter:description');

        $image = $this->metaContent($html, 'og:image')
            ?? $this->metaName($html, 'twitter:image');

        if (is_string($image) && $image !== '') {
            $image = $this->absolutizeUrl($baseUrl, $image);
            if (! $this->looksLikePublicHttpUrl($image)) {
                $image = null;
            }
        } else {
            $image = null;
        }

        return [
            'title' => $this->clip($title, 200),
            'description' => $this->clip($description, 240),
            'image' => $image,
        ];
    }

    /**
     * @return array{
     *     ok: bool,
     *     preview_url: string,
     *     preview_title: string|null,
     *     preview_description: string|null,
     *     preview_image_url: string|null,
     *     preview_domain: string|null,
     *     preview_fetched_at: string,
     *     preview_status: string,
     *     error: string|null
     * }
     */
    private function fallback(string $url, string $fetchedAt, string $status, string $error): array
    {
        return [
            'ok' => false,
            'preview_url' => $url,
            'preview_title' => null,
            'preview_description' => null,
            'preview_image_url' => null,
            'preview_domain' => $this->domainOf($url),
            'preview_fetched_at' => $fetchedAt,
            'preview_status' => $status,
            'error' => $this->clip($error, 200),
        ];
    }

    private function metaContent(string $html, string $property): ?string
    {
        $pattern = '/<meta[^>]+property=["\']'.preg_quote($property, '/').'["\'][^>]+content=["\']([^"\']*)["\']/i';
        if (preg_match($pattern, $html, $m) === 1) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $pattern2 = '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+property=["\']'.preg_quote($property, '/').'["\']/i';
        if (preg_match($pattern2, $html, $m) === 1) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return null;
    }

    private function metaName(string $html, string $name): ?string
    {
        $pattern = '/<meta[^>]+name=["\']'.preg_quote($name, '/').'["\'][^>]+content=["\']([^"\']*)["\']/i';
        if (preg_match($pattern, $html, $m) === 1) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $pattern2 = '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']'.preg_quote($name, '/').'["\']/i';
        if (preg_match($pattern2, $html, $m) === 1) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return null;
    }

    private function htmlTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) === 1) {
            return html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return null;
    }

    private function absolutizeUrl(string $base, string $maybeRelative): string
    {
        $maybeRelative = trim($maybeRelative);
        if ($maybeRelative === '') {
            return $base;
        }
        if (preg_match('#^https?://#i', $maybeRelative) === 1) {
            return $maybeRelative;
        }
        $parts = parse_url($base);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $maybeRelative;
        }
        $origin = $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($maybeRelative, '//')) {
            return $parts['scheme'].':'.$maybeRelative;
        }
        if (str_starts_with($maybeRelative, '/')) {
            return $origin.$maybeRelative;
        }
        $path = $parts['path'] ?? '/';
        $dir = preg_replace('#/[^/]*$#', '/', $path) ?: '/';

        return $origin.$dir.$maybeRelative;
    }

    private function looksLikePublicHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return false;
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === '' || in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            try {
                $this->urls->assertPublicIp($host);
            } catch (InvalidArgumentException) {
                return false;
            }
        }

        return true;
    }

    private function domainOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower(preg_replace('/^www\./i', '', $host) ?? $host);
    }

    private function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }
}
