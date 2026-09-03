<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleHeading;

/**
 * Xây compact index nội dung hiện có của site (title/slug/seo meta/heading) để phục vụ
 * mapping keyword <-> bài viết và cluster suggestion.
 * KHÔNG lưu full body — chỉ các trường "surface" gọn nhẹ + search_text ghép sẵn.
 */
final class KeywordExistingContentIndex
{
    private const MAX_HEADINGS = 20;

    /**
     * @var list<string>
     */
    private const META_TITLE_KEYS = ['seo_meta_title', '_yoast_wpseo_title', 'rank_math_title'];

    /**
     * @var list<string>
     */
    private const META_DESCRIPTION_KEYS = ['seo_meta_description', '_yoast_wpseo_metadesc', 'rank_math_description'];

    /**
     * @return list<array{
     *   article_id: int,
     *   title: string,
     *   slug: string,
     *   seo_title: string,
     *   meta_description: string,
     *   headings: list<string>,
     *   search_text: string
     * }>
     */
    public function buildForWorkspace(SeoKeywordWorkspace $workspace): array
    {
        $siteId = (int) $workspace->site_id;
        if ($siteId <= 0) {
            return [];
        }

        $articles = SeoArticle::query()
            ->where('site_id', $siteId)
            ->notContentArchived()
            ->whereNotNull('title')
            ->with([
                'articleMetas' => static function ($query): void {
                    $query->whereIn('meta_key', array_merge(self::META_TITLE_KEYS, self::META_DESCRIPTION_KEYS));
                },
                'headings:id,article_id,heading_text,level',
            ])
            ->get(['id', 'title', 'slug', 'site_id']);

        $docs = [];
        foreach ($articles as $article) {
            if (! $article instanceof SeoArticle) {
                continue;
            }

            $docs[] = $this->toDocument($article);
        }

        return $docs;
    }

    /**
     * Build + cache compact index vào workspace->summary['existing_content_index'].
     * Không dùng cột riêng — tái sử dụng cột `summary` (array) đã có sẵn trên workspace.
     *
     * @return list<array<string, mixed>>
     */
    public function buildAndCacheForWorkspace(SeoKeywordWorkspace $workspace): array
    {
        $docs = $this->buildForWorkspace($workspace);

        $summary = (array) ($workspace->summary ?? []);
        $summary['existing_content_index'] = [
            'built_at' => function_exists('now') ? now()->toIso8601String() : date('c'),
            'document_count' => count($docs),
            'documents' => $docs,
        ];
        $workspace->summary = $summary;
        $workspace->save();

        return $docs;
    }

    /**
     * @return array{
     *   article_id: int,
     *   title: string,
     *   slug: string,
     *   seo_title: string,
     *   meta_description: string,
     *   headings: list<string>,
     *   search_text: string
     * }
     */
    private function toDocument(SeoArticle $article): array
    {
        $metas = $article->articleMetas ?? collect();

        $seoTitle = $this->firstMetaValue($metas, self::META_TITLE_KEYS);
        $metaDescription = $this->firstMetaValue($metas, self::META_DESCRIPTION_KEYS);

        $headings = [];
        foreach ($article->headings ?? [] as $heading) {
            if (! $heading instanceof SeoArticleHeading) {
                continue;
            }
            $text = trim((string) ($heading->heading_text ?? ''));
            if ($text !== '') {
                $headings[] = $text;
            }
        }
        $headings = array_slice($headings, 0, self::MAX_HEADINGS);

        $title = trim((string) ($article->title ?? ''));
        $slug = trim((string) ($article->slug ?? ''), '/');

        $searchText = mb_strtolower(trim(implode(' ', array_filter([
            $title,
            $slug,
            $seoTitle,
            $metaDescription,
            implode(' ', $headings),
        ], static fn (string $part): bool => $part !== ''))), 'UTF-8');

        return [
            'article_id' => (int) $article->id,
            'title' => $title,
            'slug' => $slug,
            'seo_title' => $seoTitle,
            'meta_description' => $metaDescription,
            'headings' => $headings,
            'search_text' => $searchText,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \Omnichannel\Addons\Content\Models\ArticleMeta>  $metas
     * @param  list<string>  $keys
     */
    private function firstMetaValue($metas, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $metas->firstWhere('meta_key', $key)?->meta_value;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
