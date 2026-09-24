<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use Omnichannel\Addons\Seeding\Support\SeedingOutboundUrlPolicy;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Server-side unfurl resolver for arbitrary external Topic/comment URLs.
 * Failures never throw to callers as hard errors — return fallback payload.
 */
final class SeedingLinkPreviewService
{
    private const TIMEOUT_SECONDS = 5;

    private const MAX_REDIRECTS = 5;

    private const MAX_BODY_BYTES = 512_000;

    public function __construct(
        private readonly SeedingOutboundUrlPolicy $urls = new SeedingOutboundUrlPolicy,
        private readonly ?SeedingLinkPreviewImageCache $images = null,
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
            $image = $meta['image'];
            if (is_string($image) && $image !== '') {
                $proxied = ($this->images ?? app(SeedingLinkPreviewImageCache::class))->cachePublicImage($image);
                if (is_string($proxied) && $proxied !== '') {
                    $image = $proxied;
                }
                // Keep remote URL when proxy fails — still better than dropping metadata.
            }

            return [
                'ok' => true,
                'preview_url' => $finalUrl,
                'preview_title' => $meta['title'],
                'preview_description' => $meta['description'],
                'preview_image_url' => $image,
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
            $location = null;
            if ($status < 300 || $status >= 400) {
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
        $doc = $this->loadDom($html);
        $resolveBase = $baseUrl;

        if ($doc instanceof DOMDocument) {
            $xpath = new DOMXPath($doc);
            $baseHref = $this->firstAttr($xpath, '//base[@href]/@href');
            if (is_string($baseHref) && trim($baseHref) !== '') {
                $resolveBase = $this->absolutizeUrl($baseUrl, trim($baseHref));
            }

            $title = $this->firstNonEmpty([
                $this->metaProperty($xpath, 'og:title'),
                $this->metaName($xpath, 'twitter:title'),
                $this->domTitle($xpath),
                $this->jsonLdString($xpath, ['headline', 'name']),
            ]);

            $description = $this->firstNonEmpty([
                $this->metaProperty($xpath, 'og:description'),
                $this->metaName($xpath, 'twitter:description'),
                $this->metaName($xpath, 'description'),
                $this->jsonLdString($xpath, ['description']),
            ]);

            $image = $this->firstNonEmpty([
                $this->metaProperty($xpath, 'og:image:secure_url'),
                $this->metaProperty($xpath, 'og:image'),
                $this->metaName($xpath, 'twitter:image'),
                $this->metaName($xpath, 'twitter:image:src'),
                $this->jsonLdImage($xpath),
                $this->linkRelImageSrc($xpath),
                $this->itempropImage($xpath),
                $this->fallbackContentImage($xpath, $resolveBase),
            ]);
        } else {
            // Extremely broken HTML — last-resort regex (title/og only).
            $title = $this->regexMetaProperty($html, 'og:title')
                ?? $this->regexMetaName($html, 'twitter:title')
                ?? $this->regexHtmlTitle($html);
            $description = $this->regexMetaProperty($html, 'og:description')
                ?? $this->regexMetaName($html, 'description')
                ?? $this->regexMetaName($html, 'twitter:description');
            $image = $this->regexMetaProperty($html, 'og:image:secure_url')
                ?? $this->regexMetaProperty($html, 'og:image')
                ?? $this->regexMetaName($html, 'twitter:image');
        }

        if (is_string($image) && $image !== '') {
            $image = $this->absolutizeUrl($resolveBase, $image);
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

    private function loadDom(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            $wrapped = '<?xml encoding="UTF-8">'.$html;
            $ok = @$doc->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
            if (! $ok) {
                return null;
            }

            return $doc;
        } catch (Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function metaProperty(DOMXPath $xpath, string $property): ?string
    {
        $q = strtolower($property);
        foreach ($xpath->query('//meta[@property]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if (strtolower(trim($node->getAttribute('property'))) !== $q) {
                continue;
            }
            $content = trim($node->getAttribute('content'));
            if ($content !== '') {
                return $this->decode($content);
            }
        }

        return null;
    }

    private function metaName(DOMXPath $xpath, string $name): ?string
    {
        $q = strtolower($name);
        foreach ($xpath->query('//meta[@name]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if (strtolower(trim($node->getAttribute('name'))) !== $q) {
                continue;
            }
            $content = trim($node->getAttribute('content'));
            if ($content !== '') {
                return $this->decode($content);
            }
        }

        return null;
    }

    private function domTitle(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query('//title');
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $text = trim($nodes->item(0)?->textContent ?? '');

        return $text !== '' ? $this->decode($text) : null;
    }

    private function firstAttr(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $node = $nodes->item(0);
        if ($node instanceof DOMNode) {
            $val = trim($node->nodeValue ?? '');

            return $val !== '' ? $val : null;
        }

        return null;
    }

    /**
     * @param  list<string>  $keys
     */
    private function jsonLdString(DOMXPath $xpath, array $keys): ?string
    {
        foreach ($this->jsonLdDocuments($xpath) as $data) {
            $found = $this->walkJsonLdForString($data, $keys);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function jsonLdImage(DOMXPath $xpath): ?string
    {
        foreach ($this->jsonLdDocuments($xpath) as $data) {
            $found = $this->walkJsonLdForImage($data);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return list<mixed>
     */
    private function jsonLdDocuments(DOMXPath $xpath): array
    {
        $out = [];
        foreach ($xpath->query('//script[@type]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $type = strtolower(trim($node->getAttribute('type')));
            if ($type !== 'application/ld+json') {
                continue;
            }
            $raw = trim($node->textContent ?? '');
            if ($raw === '') {
                continue;
            }
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            $out[] = $decoded;
        }

        return $out;
    }

    /**
     * @param  list<string>  $keys
     */
    private function walkJsonLdForString(mixed $data, array $keys): ?string
    {
        if (is_array($data)) {
            if ($this->isList($data)) {
                foreach ($data as $row) {
                    $found = $this->walkJsonLdForString($row, $keys);
                    if ($found !== null) {
                        return $found;
                    }
                }

                return null;
            }
            foreach ($keys as $key) {
                if (! array_key_exists($key, $data)) {
                    continue;
                }
                $val = $data[$key];
                if (is_string($val) && trim($val) !== '') {
                    return $this->decode(trim($val));
                }
            }
            if (isset($data['@graph'])) {
                return $this->walkJsonLdForString($data['@graph'], $keys);
            }
            foreach ($data as $val) {
                if (is_array($val)) {
                    $found = $this->walkJsonLdForString($val, $keys);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    private function walkJsonLdForImage(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }
        if ($this->isList($data)) {
            foreach ($data as $row) {
                $found = $this->walkJsonLdForImage($row);
                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        }

        foreach (['image', 'thumbnailUrl', 'primaryImageOfPage'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $extracted = $this->coerceImageValue($data[$key]);
            if ($extracted !== null) {
                return $extracted;
            }
        }

        if (isset($data['@graph'])) {
            return $this->walkJsonLdForImage($data['@graph']);
        }

        foreach ($data as $val) {
            if (is_array($val)) {
                $found = $this->walkJsonLdForImage($val);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function coerceImageValue(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return $this->decode(trim($value));
        }
        if (! is_array($value)) {
            return null;
        }
        if ($this->isList($value)) {
            foreach ($value as $row) {
                $found = $this->coerceImageValue($row);
                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        }
        foreach (['url', 'contentUrl', '@id'] as $key) {
            if (isset($value[$key]) && is_string($value[$key]) && trim($value[$key]) !== '') {
                return $this->decode(trim($value[$key]));
            }
        }

        return null;
    }

    private function linkRelImageSrc(DOMXPath $xpath): ?string
    {
        foreach ($xpath->query('//link[@rel]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $rel = strtolower(trim($node->getAttribute('rel')));
            if ($rel !== 'image_src' && $rel !== 'image_src ') {
                continue;
            }
            $href = trim($node->getAttribute('href'));
            if ($href !== '') {
                return $this->decode($href);
            }
        }

        return null;
    }

    private function itempropImage(DOMXPath $xpath): ?string
    {
        foreach ($xpath->query('//*[@itemprop]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if (strtolower(trim($node->getAttribute('itemprop'))) !== 'image') {
                continue;
            }
            $content = trim($node->getAttribute('content'));
            if ($content !== '') {
                return $this->decode($content);
            }
            $src = trim($node->getAttribute('src'));
            if ($src !== '') {
                return $this->decode($src);
            }
            $href = trim($node->getAttribute('href'));
            if ($href !== '') {
                return $this->decode($href);
            }
        }

        return null;
    }

    private function fallbackContentImage(DOMXPath $xpath, string $baseUrl): ?string
    {
        $scopes = [
            '//article//img[@src]',
            '//main//img[@src]',
            '//*[@role="main"]//img[@src]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " content ")]//img[@src]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " post ")]//img[@src]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " entry ")]//img[@src]',
            '//body//img[@src]',
        ];

        $bestUrl = null;
        $bestScore = -1;

        foreach ($scopes as $scope) {
            foreach ($xpath->query($scope) ?: [] as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $src = trim($node->getAttribute('src'));
                if ($src === '' || str_starts_with(strtolower($src), 'data:')) {
                    continue;
                }
                $abs = $this->absolutizeUrl($baseUrl, $src);
                if (! $this->looksLikePublicHttpUrl($abs)) {
                    continue;
                }
                $score = $this->scoreImageCandidate($node, $abs);
                if ($score < 0) {
                    continue;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestUrl = $abs;
                }
            }
            if ($bestUrl !== null && $bestScore >= 40) {
                break;
            }
        }

        return $bestUrl;
    }

    private function scoreImageCandidate(DOMElement $img, string $absUrl): int
    {
        $hay = strtolower(
            $absUrl.' '
            .$img->getAttribute('src').' '
            .$img->getAttribute('class').' '
            .$img->getAttribute('id').' '
            .$img->getAttribute('alt').' '
            .$img->getAttribute('width').' '
            .$img->getAttribute('height')
        );

        foreach ([
            'logo', 'favicon', 'icon', 'avatar', 'sprite', 'pixel', 'tracking',
            '1x1', 'spacer', 'badge', 'emoji', 'spinner', 'loading',
        ] as $bad) {
            if (str_contains($hay, $bad)) {
                return -1;
            }
        }

        $width = (int) $img->getAttribute('width');
        $height = (int) $img->getAttribute('height');
        if (($width > 0 && $width < 80) || ($height > 0 && $height < 80)) {
            return -1;
        }
        if ($width > 0 && $height > 0 && ($width * $height) < 8_000) {
            return -1;
        }

        $score = 10;
        if ($width >= 200 || $height >= 200) {
            $score += 30;
        } elseif ($width >= 120 || $height >= 120) {
            $score += 15;
        }
        if (str_contains($hay, 'hero') || str_contains($hay, 'featured') || str_contains($hay, 'cover')) {
            $score += 20;
        }
        $parent = $img->parentNode;
        while ($parent instanceof DOMElement) {
            $tag = strtolower($parent->tagName);
            if (in_array($tag, ['article', 'main', 'figure'], true)) {
                $score += 15;
                break;
            }
            $parent = $parent->parentNode;
        }

        return $score;
    }

    /**
     * @param  list<?string>  $candidates
     */
    private function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (is_string($c) && trim($c) !== '') {
                return trim($c);
            }
        }

        return null;
    }

    private function isList(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }

        return array_keys($arr) === range(0, count($arr) - 1);
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

    private function regexMetaProperty(string $html, string $property): ?string
    {
        $pattern = '/<meta[^>]+property=["\']'.preg_quote($property, '/').'["\'][^>]+content=["\']([^"\']*)["\']/i';
        if (preg_match($pattern, $html, $m) === 1) {
            return $this->decode(trim($m[1]));
        }
        $pattern2 = '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+property=["\']'.preg_quote($property, '/').'["\']/i';
        if (preg_match($pattern2, $html, $m) === 1) {
            return $this->decode(trim($m[1]));
        }

        return null;
    }

    private function regexMetaName(string $html, string $name): ?string
    {
        $pattern = '/<meta[^>]+name=["\']'.preg_quote($name, '/').'["\'][^>]+content=["\']([^"\']*)["\']/i';
        if (preg_match($pattern, $html, $m) === 1) {
            return $this->decode(trim($m[1]));
        }
        $pattern2 = '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']'.preg_quote($name, '/').'["\']/i';
        if (preg_match($pattern2, $html, $m) === 1) {
            return $this->decode(trim($m[1]));
        }

        return null;
    }

    private function regexHtmlTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) === 1) {
            return $this->decode(trim(strip_tags($m[1])));
        }

        return null;
    }

    public function absolutizeUrl(string $base, string $maybeRelative): string
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

    private function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
