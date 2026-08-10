<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\SiteMcp;

/**
 * Keyword extraction + normalization for Site MCP.
 *
 * Priority for a single page: SEO keyword → SEO title → page title → slug.
 * Product titles must never become fake categories.
 */
final class SiteMcpKeywordExtractor
{
    /** @var list<string> */
    private const BOILERPLATE_SUFFIX_MARKERS = [
        '|',
        ' - ',
        ' – ',
        ' — ',
        ' · ',
    ];

    /**
     * @param  array{
     *     focus_keyword?: string|null,
     *     seo_title?: string|null,
     *     title?: string|null,
     *     slug?: string|null
     * }  $page
     * @return array{keyword: string, source: string, confidence: float}
     */
    public function extract(array $page): array
    {
        $focus = $this->normalize((string) ($page['focus_keyword'] ?? ''));
        if ($focus !== '' && ! $this->isBoilerplate($focus)) {
            return ['keyword' => $focus, 'source' => 'seo_keyword', 'confidence' => 0.9];
        }

        $seoTitle = $this->normalize((string) ($page['seo_title'] ?? ''));
        if ($seoTitle !== '') {
            $topic = $this->titleToTopic($seoTitle);
            if ($topic !== '' && ! $this->isBoilerplate($topic)) {
                return ['keyword' => $topic, 'source' => 'seo_title', 'confidence' => 0.7];
            }
        }

        $title = $this->normalize((string) ($page['title'] ?? ''));
        if ($title !== '') {
            $topic = $this->titleToTopic($title);
            if ($topic !== '' && ! $this->isBoilerplate($topic)) {
                return ['keyword' => $topic, 'source' => 'page_title', 'confidence' => 0.5];
            }
        }

        $slug = $this->normalizeSlug((string) ($page['slug'] ?? ''));
        if ($slug !== '' && ! $this->isBoilerplate($slug)) {
            return ['keyword' => $slug, 'source' => 'slug', 'confidence' => 0.3];
        }

        return ['keyword' => '', 'source' => 'none', 'confidence' => 0.0];
    }

    /**
     * Category-safe extract: prefer focus/title of taxonomy term, never invent from product.
     *
     * @param  array<string, mixed>  $category
     * @return array{keyword: string, source: string, confidence: float}
     */
    public function extractCategoryTopic(array $category): array
    {
        return $this->extract([
            'focus_keyword' => $category['focus_keyword'] ?? '',
            'seo_title' => $category['seo_title'] ?? '',
            'title' => $category['title'] ?? $category['name'] ?? '',
            'slug' => $category['slug'] ?? '',
        ]);
    }

    /**
     * @param  list<string>  $topics
     * @return list<string>
     */
    public function uniqueTopics(array $topics): array
    {
        $seen = [];
        $out = [];
        foreach ($topics as $topic) {
            $normalized = $this->normalize($topic);
            if ($normalized === '' || $this->isBoilerplate($normalized)) {
                continue;
            }
            $key = mb_strtolower($normalized);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $normalized;
        }

        return $out;
    }

    public function normalize(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                $value = $normalized;
            }
        }
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return $value;
    }

    public function isLikelyProductSpecificTitle(string $title): bool
    {
        $title = $this->normalize($title);
        if ($title === '') {
            return false;
        }

        // Customer / brand / model heavy titles often include proper nouns after "May ...".
        if (preg_match('/\b(sku|model|mã)\b/ui', $title) === 1) {
            return true;
        }

        // Very long titles with many capitalized tokens tend to be product SKUs / custom jobs.
        $words = preg_split('/\s+/u', $title) ?: [];
        if (count($words) >= 8) {
            return true;
        }

        return false;
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }
        $slug = rawurldecode($slug);
        $slug = str_replace(['-', '_'], ' ', $slug);

        return $this->normalize($slug);
    }

    private function titleToTopic(string $title): string
    {
        foreach (self::BOILERPLATE_SUFFIX_MARKERS as $marker) {
            $pos = mb_strpos($title, $marker);
            if ($pos !== false && $pos > 2) {
                $title = mb_substr($title, 0, $pos);
                break;
            }
        }

        return $this->normalize($title);
    }

    private function isBoilerplate(string $value): bool
    {
        $lower = mb_strtolower($value);
        if ($lower === '' || $lower === 'uncategorized' || $lower === 'chưa phân loại') {
            return true;
        }

        return in_array($lower, ['home', 'trang chủ', 'blog', 'shop', 'sản phẩm'], true);
    }
}
