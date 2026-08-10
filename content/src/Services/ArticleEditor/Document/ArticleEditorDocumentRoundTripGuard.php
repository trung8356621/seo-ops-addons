<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleEditor\Document;

/**
 * Semantic (not byte) equivalence for HTML → JSON → HTML round-trip.
 */
final class ArticleEditorDocumentRoundTripGuard
{
    /**
     * @return array{equivalent: bool, reasons: list<string>}
     */
    public function compare(string $originalHtml, string $renderedHtml): array
    {
        $reasons = [];
        $a = $this->fingerprint($originalHtml);
        $b = $this->fingerprint($renderedHtml);

        foreach (['text', 'headings', 'links', 'images', 'lists', 'blockquotes', 'faq', 'cta'] as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                $reasons[] = $key;
            }
        }

        return [
            'equivalent' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fingerprint(string $html): array
    {
        $source = (string) $html;

        return [
            'text' => $this->normalizeText(strip_tags($source)),
            'headings' => preg_match_all('/<h([1-6])\b/i', $source) ?: 0,
            'links' => preg_match_all('/<a\b[^>]*\bhref=/i', $source) ?: 0,
            'images' => preg_match_all('/<img\b/i', $source) ?: 0,
            'lists' => (preg_match_all('/<(ul|ol)\b/i', $source) ?: 0),
            'blockquotes' => preg_match_all('/<blockquote\b/i', $source) ?: 0,
            'faq' => (int) (preg_match('/omi-faq-placeholder|\[omi_faq\]/i', $source) === 1),
            'cta' => (int) (preg_match('/article-cta/i', $source) === 1),
        ];
    }

    private function normalizeText(string $text): string
    {
        $value = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim(mb_strtolower($value));
    }
}
