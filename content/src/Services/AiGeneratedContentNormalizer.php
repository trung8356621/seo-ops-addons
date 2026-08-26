<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * Safety-net typography normalization for AI-generated article HTML.
 *
 * Inserts a single space after prose punctuation (, ; : ! ?) when immediately
 * followed by a Unicode letter. Does NOT split glued Vietnamese words (e.g. sổtay).
 *
 * HTML-aware: text nodes only; skips code/pre/script/style and URL/email segments.
 */
final class AiGeneratedContentNormalizer
{
    private const SKIP_TAGS = ['code', 'pre', 'script', 'style', 'kbd', 'samp'];

    /**
     * Punctuation that may lack a following space before a letter.
     * Period (.) intentionally excluded — domains / decimals / version strings.
     */
    private const PUNCT = '[,;:!?…]';

    public function normalizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"><div id="omi-typo-root">'.$html.'</div>';
        $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded !== true) {
            return $html;
        }

        $root = $doc->getElementById('omi-typo-root');
        if (! $root instanceof DOMElement) {
            return $html;
        }

        $xpath = new DOMXPath($doc);
        /** @var \DOMNodeList<int, DOMNode> $nodes */
        $nodes = $xpath->query('.//text()', $root);
        if ($nodes === false) {
            return $html;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMText) {
                continue;
            }
            if ($this->isInsideSkippedElement($node)) {
                continue;
            }

            $original = $node->nodeValue ?? '';
            if ($original === '' || ! preg_match('/'.self::PUNCT.'/u', $original)) {
                continue;
            }

            $normalized = $this->normalizePlainText($original);
            if ($normalized !== $original) {
                $node->nodeValue = $normalized;
            }
        }

        $parts = [];
        foreach ($root->childNodes as $child) {
            $parts[] = $doc->saveHTML($child);
        }

        return trim(implode('', $parts));
    }

    public function normalizePlainText(string $text): string
    {
        if ($text === '' || ! preg_match('/'.self::PUNCT.'/u', $text)) {
            return $text;
        }

        $segments = $this->splitProtectingUrlsAndEmails($text);
        $out = [];
        foreach ($segments as [$kind, $segment]) {
            $out[] = $kind === 'protect'
                ? $segment
                : $this->normalizeProseSegment($segment);
        }

        return implode('', $out);
    }

    private function normalizeProseSegment(string $text): string
    {
        // Insert space after punctuation when followed by a Unicode letter.
        // Digits after punctuation stay untouched (\p{L} only) — keeps 10,000 / 1.5.
        $result = preg_replace(
            '/('.self::PUNCT.')(?=\p{L})/u',
            '$1 ',
            $text,
        );

        return is_string($result) ? $result : $text;
    }

    /**
     * @return list<array{0: 'protect'|'prose', 1: string}>
     */
    private function splitProtectingUrlsAndEmails(string $text): array
    {
        $pattern = '/(?:'
            .'https?:\/\/[^\s<>"\']+'
            .'|www\.[^\s<>"\']+'
            .'|[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}'
            .')/u';

        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false
            || ($matches[0] ?? []) === []) {
            return [['prose', $text]];
        }

        $segments = [];
        $cursor = 0;
        foreach ($matches[0] as [$match, $byteOffset]) {
            $offset = $this->byteOffsetToCharOffset($text, (int) $byteOffset);
            if ($offset > $cursor) {
                $segments[] = ['prose', mb_substr($text, $cursor, $offset - $cursor)];
            }
            $segments[] = ['protect', $match];
            $cursor = $offset + mb_strlen($match);
        }
        if ($cursor < mb_strlen($text)) {
            $segments[] = ['prose', mb_substr($text, $cursor)];
        }

        return $segments;
    }

    private function byteOffsetToCharOffset(string $text, int $byteOffset): int
    {
        if ($byteOffset <= 0) {
            return 0;
        }

        return mb_strlen(substr($text, 0, $byteOffset));
    }

    private function isInsideSkippedElement(DOMNode $node): bool
    {
        $parent = $node->parentNode;
        while ($parent instanceof DOMNode) {
            if ($parent instanceof DOMElement) {
                $tag = strtolower($parent->tagName);
                if (in_array($tag, self::SKIP_TAGS, true)) {
                    return true;
                }
            }
            $parent = $parent->parentNode;
        }

        return false;
    }
}
