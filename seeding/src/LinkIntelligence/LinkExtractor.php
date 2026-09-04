<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\LinkIntelligence;

use Omnichannel\Addons\Seeding\LinkIntelligence\Dto\ExtractedLink;

/**
 * Extract http(s) URLs from HTML anchors, markdown links, then plain text.
 * Never invents URLs from display text (e.g. truncated s.shopee.vn/…).
 */
final class LinkExtractor
{
    public function __construct(
        private readonly UrlNormalizer $normalizer = new UrlNormalizer,
    ) {}

    /**
     * @return list<ExtractedLink>
     */
    public function extract(?string $fullText, ?string $sourceHtml = null): array
    {
        $text = is_string($fullText) ? $fullText : '';
        $html = is_string($sourceHtml) ? $sourceHtml : '';

        $ordered = [];
        $seenNormalized = [];

        foreach ($this->extractHtmlAnchors($html !== '' ? $html : $text) as $url) {
            $this->push($ordered, $seenNormalized, $url, 'html_anchor');
        }

        foreach ($this->extractMarkdownLinks($text) as $url) {
            $this->push($ordered, $seenNormalized, $url, 'markdown');
        }

        foreach ($this->extractPlainUrls($text) as $url) {
            $this->push($ordered, $seenNormalized, $url, 'plain');
        }

        return $ordered;
    }

    /**
     * @return list<string>
     */
    private function extractHtmlAnchors(string $content): array
    {
        if ($content === '' || ! str_contains(strtolower($content), '<a')) {
            return [];
        }

        $urls = [];
        if (preg_match_all(
            '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>/i',
            $content,
            $matches,
        ) === false) {
            return [];
        }

        foreach ($matches[2] as $href) {
            $href = html_entity_decode(trim((string) $href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($href !== '') {
                $urls[] = $href;
            }
        }

        return $urls;
    }

    /**
     * @return list<string>
     */
    private function extractMarkdownLinks(string $text): array
    {
        if ($text === '' || ! str_contains($text, '](')) {
            return [];
        }

        $urls = [];
        // [display](url) — capture target URL only, never display text.
        if (preg_match_all('/\[[^\]]*]\(\s*(<)?(https?:\/\/[^)\s>]+)(>)?\s*\)/i', $text, $matches) === false) {
            return [];
        }

        foreach ($matches[2] as $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * @return list<string>
     */
    private function extractPlainUrls(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $urls = [];
        // Scheme-required only — rejects bare hosts like s.shopee.vn/2BCY1…
        if (preg_match_all('#https?://[^\s<>"\'\])\}]+#i', $text, $matches) === false) {
            return [];
        }

        foreach ($matches[0] as $raw) {
            $url = rtrim((string) $raw, '.,;:!?)]}');
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * @param  list<ExtractedLink>  $ordered
     * @param  array<string, true>  $seenNormalized
     */
    private function push(array &$ordered, array &$seenNormalized, string $rawUrl, string $source): void
    {
        $normalized = $this->normalizer->normalize($rawUrl);
        if ($normalized === null) {
            return;
        }

        $key = $normalized['normalized_url'];
        if (isset($seenNormalized[$key])) {
            return;
        }

        $seenNormalized[$key] = true;
        $ordered[] = new ExtractedLink(
            originalUrl: $normalized['original_url'],
            normalizedUrl: $normalized['normalized_url'],
            domain: $normalized['domain'],
            source: $source,
        );
    }
}
