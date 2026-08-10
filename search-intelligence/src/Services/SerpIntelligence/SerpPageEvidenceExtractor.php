<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

/**
 * Extract lightweight page evidence — không persist full HTML.
 */
final class SerpPageEvidenceExtractor
{
    public const CONTENT_GAP_EXTRACTOR_VERSION = '1.0.0';

    /**
     * @return array{
     *   title: ?string,
     *   meta_description: ?string,
     *   canonical_url: ?string,
     *   headings: array{h1: list<string>, h2: list<string>, h3: list<string>},
     *   schema_types: list<string>,
     *   word_count_approx: int,
     *   media_count: int,
     *   table_count: int,
     *   faq_count: int,
     *   entities: list<string>,
     *   extractor_version: string
     * }
     */
    public function extract(?string $html, ?array $metadata = null): array
    {
        if ($html === null || trim($html) === '') {
            return $this->emptyEvidence($metadata);
        }

        $title = $this->matchTag($html, 'title');
        $metaDescription = $this->matchMeta($html, 'description');
        $canonical = $this->matchLinkRel($html, 'canonical');

        return [
            'title' => $title,
            'meta_description' => $metaDescription,
            'canonical_url' => $canonical,
            'headings' => [
                'h1' => $this->matchHeadings($html, 'h1'),
                'h2' => $this->matchHeadings($html, 'h2'),
                'h3' => $this->matchHeadings($html, 'h3'),
            ],
            'schema_types' => $this->extractSchemaTypes($html),
            'word_count_approx' => $this->approxWordCount($html),
            'media_count' => $this->countPattern($html, '/<(img|video|iframe)\b/i'),
            'table_count' => $this->countPattern($html, '/<table\b/i'),
            'faq_count' => $this->countFaqSignals($html),
            'entities' => $this->extractLightEntities($html),
            'extractor_version' => self::CONTENT_GAP_EXTRACTOR_VERSION,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyEvidence(?array $metadata): array
    {
        return [
            'title' => is_array($metadata) ? ($metadata['title'] ?? null) : null,
            'meta_description' => is_array($metadata) ? ($metadata['description'] ?? null) : null,
            'canonical_url' => null,
            'headings' => ['h1' => [], 'h2' => [], 'h3' => []],
            'schema_types' => [],
            'word_count_approx' => 0,
            'media_count' => 0,
            'table_count' => 0,
            'faq_count' => 0,
            'entities' => [],
            'extractor_version' => self::CONTENT_GAP_EXTRACTOR_VERSION,
        ];
    }

    private function matchTag(string $html, string $tag): ?string
    {
        if (preg_match('/<'.$tag.'[^>]*>(.*?)<\/'.$tag.'>/is', $html, $matches) !== 1) {
            return null;
        }

        return $this->cleanText($matches[1]);
    }

    private function matchMeta(string $html, string $name): ?string
    {
        if (preg_match('/<meta[^>]+name=["\']'.preg_quote($name, '/').'["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/i', $html, $matches) === 1) {
            return $this->cleanText($matches[1]);
        }

        if (preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']'.preg_quote($name, '/').'["\'][^>]*>/i', $html, $matches) === 1) {
            return $this->cleanText($matches[1]);
        }

        return null;
    }

    private function matchLinkRel(string $html, string $rel): ?string
    {
        if (preg_match('/<link[^>]+rel=["\']'.preg_quote($rel, '/').'["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /** @return list<string> */
    private function matchHeadings(string $html, string $tag): array
    {
        if (preg_match_all('/<'.$tag.'[^>]*>(.*?)<\/'.$tag.'>/is', $html, $matches) < 1) {
            return [];
        }

        $headings = [];
        foreach ($matches[1] as $raw) {
            $text = $this->cleanText((string) $raw);
            if ($text !== '') {
                $headings[] = $text;
            }
        }

        return array_values(array_unique($headings));
    }

    /** @return list<string> */
    private function extractSchemaTypes(string $html): array
    {
        $types = [];
        if (preg_match_all('/"@type"\s*:\s*"([^"]+)"/i', $html, $matches) > 0) {
            foreach ($matches[1] as $type) {
                $types[] = mb_strtolower(trim((string) $type), 'UTF-8');
            }
        }

        if (preg_match_all('/itemtype=["\']https?:\/\/schema\.org\/([^"\']+)["\']/i', $html, $matches) > 0) {
            foreach ($matches[1] as $type) {
                $types[] = mb_strtolower(trim((string) $type), 'UTF-8');
            }
        }

        return array_values(array_unique($types));
    }

    private function approxWordCount(string $html): int
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $text) ?: []);
    }

    private function countPattern(string $html, string $pattern): int
    {
        if (preg_match_all($pattern, $html) === false) {
            return 0;
        }

        return preg_match_all($pattern, $html);
    }

    private function countFaqSignals(string $html): int
    {
        $jsonLdFaq = preg_match_all('/"@type"\s*:\s*"FAQPage"/i', $html) ?: 0;
        $detailsFaq = preg_match_all('/<(details|summary)\b/i', $html) ?: 0;

        return max($jsonLdFaq, (int) floor($detailsFaq / 2));
    }

    /** @return list<string> */
    private function extractLightEntities(string $html): array
    {
        $entities = [];
        if (preg_match_all('/itemprop=["\'](name|brand|author)["\'][^>]*>([^<]{2,80})</i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $entities[] = $this->cleanText($match[2]) ?? '';
            }
        }

        return array_values(array_filter(array_unique($entities)));
    }

    private function cleanText(string $value): ?string
    {
        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        return $text !== '' ? $text : null;
    }
}
