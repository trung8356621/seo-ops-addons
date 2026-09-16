<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use DOMDocument;
use DOMElement;
use Omnichannel\Addons\Content\Contracts\AiGeneratedLinkDestinationGate;
use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Gen / markdown-import boundary for AI article links.
 *
 * Separates anchor suggestion from verified destination:
 * - Preserve raw HTML &lt;a&gt; that CommonMark html_input=strip would unwrap
 * - Keep only authoritative WordPress destinations as real hrefs
 * - Unverified / hallucinated destinations become editor placeholder href="#"
 *
 * Does not invent domain+slug URLs.
 */
final class AiGeneratedArticleLinkNormalizer
{
    public const PLACEHOLDER_HREF = '#';

    public function __construct(
        private readonly AiGeneratedLinkDestinationGate $destinationGate,
    ) {}

    /**
     * Convert raw HTML anchors in AI markdown to Markdown links so CommonMark
     * (html_input=strip) cannot drop the anchor suggestion.
     */
    public function preserveRawHtmlAnchorsInMarkdown(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '' || ! str_contains(strtolower($markdown), '<a')) {
            return $markdown;
        }

        $replaced = preg_replace_callback(
            '/<a\b([^>]*)>(.*?)<\/a>/is',
            function (array $matches): string {
                $attrs = (string) ($matches[1] ?? '');
                $inner = (string) ($matches[2] ?? '');
                $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
                if ($text === '') {
                    return $text;
                }

                $href = $this->extractHrefFromAttributes($attrs);
                $destination = $this->normalizeDestinationForMarkdown($href);

                $safeText = str_replace(['[', ']', '\\'], ['\\[', '\\]', '\\\\'], $text);

                return '['.$safeText.']('.$destination.')';
            },
            $markdown,
        );

        return is_string($replaced) ? $replaced : $markdown;
    }

    /**
     * After Markdown→HTML: keep verified WP destinations; placeholder the rest.
     * Never unwrap anchors — anchor text is the Gen suggestion signal.
     */
    public function rewriteUnverifiedDestinations(string $html, SeoArticle $article): string
    {
        $html = trim($html);
        if ($html === '' || ! str_contains(strtolower($html), '<a')) {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"><div id="omi-ai-link-root">'.$html.'</div>';
        $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded !== true) {
            return $html;
        }

        $root = $doc->getElementById('omi-ai-link-root');
        if (! $root instanceof DOMElement) {
            return $html;
        }

        $anchors = [];
        foreach ($root->getElementsByTagName('a') as $anchor) {
            if ($anchor instanceof DOMElement) {
                $anchors[] = $anchor;
            }
        }

        foreach ($anchors as $anchor) {
            $href = trim((string) $anchor->getAttribute('href'));
            $anchor->setAttribute('href', $this->resolveEditorHref($href, $article));
        }

        $parts = [];
        foreach ($root->childNodes as $child) {
            $parts[] = $doc->saveHTML($child);
        }

        return trim(implode('', $parts));
    }

    public function isVerifiedDestination(string $href, SeoArticle $article): bool
    {
        return $this->destinationGate->isVerifiedDestination($href, $article);
    }

    private function resolveEditorHref(string $href, SeoArticle $article): string
    {
        if ($this->isEditorPlaceholderHref($href)) {
            return $href === '' ? self::PLACEHOLDER_HREF : $href;
        }

        if ($this->isContactOrSpecialScheme($href)) {
            return $href;
        }

        if ($this->destinationGate->isVerifiedDestination($href, $article)) {
            $authoritative = $this->destinationGate->authoritativePermalink($href, $article);

            return $authoritative !== null && $authoritative !== ''
                ? $authoritative
                : $href;
        }

        return self::PLACEHOLDER_HREF;
    }

    private function extractHrefFromAttributes(string $attrs): string
    {
        if (preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $attrs, $match) === 1) {
            return trim(html_entity_decode((string) ($match[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        if (preg_match('/\bhref\s*=\s*([^\s>]+)/i', $attrs, $match) === 1) {
            return trim(html_entity_decode(trim((string) ($match[1] ?? ''), "\"'"), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private function normalizeDestinationForMarkdown(string $href): string
    {
        $href = trim($href);
        if ($href === '' || $this->isUnsafeScheme($href)) {
            return self::PLACEHOLDER_HREF;
        }

        if (str_contains($href, '(') || str_contains($href, ')')) {
            return self::PLACEHOLDER_HREF;
        }

        return $href;
    }

    private function isEditorPlaceholderHref(string $href): bool
    {
        $href = trim($href);
        if ($href === '' || $href === self::PLACEHOLDER_HREF) {
            return true;
        }

        return str_starts_with($href, '#');
    }

    private function isContactOrSpecialScheme(string $href): bool
    {
        $lower = strtolower(trim($href));

        return str_starts_with($lower, 'mailto:')
            || str_starts_with($lower, 'tel:')
            || str_starts_with($lower, 'sms:');
    }

    private function isUnsafeScheme(string $href): bool
    {
        $lower = strtolower(trim($href));

        return str_starts_with($lower, 'javascript:')
            || str_starts_with($lower, 'data:')
            || str_starts_with($lower, 'vbscript:');
    }
}
