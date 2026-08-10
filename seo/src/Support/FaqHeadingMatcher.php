<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Illuminate\Support\Str;

/**
 * So khớp tiêu đề Markdown/HTML với faq_catch_keywords (SEO Settings → Nhận diện FAQ).
 */
final class FaqHeadingMatcher
{
    /** @var list<string> */
    private array $keywords;

    /**
     * @param  list<string>  $keywords
     */
    public function __construct(array $keywords)
    {
        $this->keywords = $this->dedupeNormalizedKeywords($keywords);
    }

    /**
     * @return list<string>
     */
    public function keywords(): array
    {
        return $this->keywords;
    }

    public function matches(string $heading): bool
    {
        $headingText = $this->extractHeadingText($heading);
        if ($headingText === '') {
            return false;
        }

        $normalizedHeading = $this->normalize($headingText);
        $asciiHeading = $this->normalizeAscii($headingText);

        if ($normalizedHeading === '' && $asciiHeading === '') {
            return false;
        }

        foreach ($this->keywords as $keyword) {
            $normalizedKeyword = $this->normalize($keyword);
            if ($normalizedKeyword !== '' && $this->containsKeywordTokens($normalizedHeading, $normalizedKeyword)) {
                return true;
            }

            $asciiKeyword = $this->normalizeAscii($keyword);
            if ($asciiKeyword !== '' && $this->containsKeywordTokens($asciiHeading, $asciiKeyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Chuẩn hóa tiêu đề / từ khóa để so khớp (không đổi text gốc bài viết).
     */
    public function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(str_replace(['**', '__', '*', '_'], '', $text));
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/^#{1,6}\s+/u', '', $text) ?? $text;
        $text = preg_replace('/^(?:Section\s+\d+:\s*)?H[1-6]:\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/^\d+\s*[\.\)\-–—:]\s+/u', '', $text) ?? $text;
        $text = rtrim($text, " \t\n\r\0\x0B:：");

        $lower = mb_strtolower(trim($text), 'UTF-8');
        $lower = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lower) ?? $lower;

        return trim(preg_replace('/\s+/u', ' ', $lower) ?? $lower);
    }

    private function normalizeAscii(string $text): string
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return '';
        }

        $ascii = Str::ascii($normalized, 'vi');
        $ascii = mb_strtolower(trim($ascii), 'UTF-8');
        $ascii = preg_replace('/[^a-z0-9]+/u', ' ', $ascii) ?? $ascii;

        return trim(preg_replace('/\s+/u', ' ', $ascii) ?? $ascii);
    }

    private function extractHeadingText(string $headingLine): string
    {
        $trimmed = trim($headingLine);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^#{1,6}\s+(.*)$/u', $trimmed, $matches) === 1) {
            return trim(str_replace(['**', '__', '*', '_'], '', $matches[1]));
        }

        if (preg_match('/^Section\s+\d+:\s*H[1-6]:\s*(.+)$/iu', $trimmed, $matches) === 1) {
            return trim(str_replace(['**', '__', '*', '_'], '', $matches[1]));
        }

        if (preg_match('/^H[1-6]:\s*(.+)$/iu', $trimmed, $matches) === 1) {
            return trim(str_replace(['**', '__', '*', '_'], '', $matches[1]));
        }

        return trim(str_replace(['**', '__', '*', '_'], '', $trimmed));
    }

    private function containsKeywordTokens(string $haystack, string $needle): bool
    {
        if ($haystack === '' || $needle === '') {
            return false;
        }

        if ($haystack === $needle) {
            return true;
        }

        $pattern = '/(?:^|\s)'.preg_quote($needle, '/').'(?:\s|$)/u';

        return preg_match($pattern, $haystack) === 1;
    }

    /**
     * @param  list<string>  $keywords
     * @return list<string>
     */
    private function dedupeNormalizedKeywords(array $keywords): array
    {
        $result = [];
        $seen = [];

        foreach ($keywords as $keyword) {
            $label = trim((string) $keyword);
            if ($label === '') {
                continue;
            }

            $normalized = $this->normalize($label);
            $key = $normalized !== '' ? $normalized : mb_strtolower($label, 'UTF-8');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $label;
        }

        return $result;
    }
}
