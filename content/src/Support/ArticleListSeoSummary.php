<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;


use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Services\ArticleContentSeoBonusService;
use Omnichannel\Addons\Media\Services\ArticlePostImagesService;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;

final class ArticleListSeoSummary
{
    /**
     * List-row SEO summary — local meta/columns only.
     * Must never call WordPress HTTP, parse full article HTML, or write meta.
     *
     * @return array{
     *     score: ?int,
     *     score_skipped: bool,
     *     score_tone: string,
     *     keyword: ?string,
     *     schema: string,
     *     links_total: int,
     *     links_internal: int,
     *     links_external: int,
     *     image_count: int,
     *     faq_count: int,
     *     featured_snippet_points: int,
     *     faq_points: int,
     *     edit_url: string,
     * }
     */
    public static function for(SeoArticle $article): array
    {
        $article->loadMissing(['articleMetas', 'faqs']);

        $keyword = self::resolveFocusKeywordFromMeta($article);

        $attrs = $article->getAttributes();
        // Prefer stored counters only — never hydrate link maps / permalink / WP on list.
        $internal = array_key_exists('internal_link_count', $attrs) ? (int) $attrs['internal_link_count'] : 0;
        $external = array_key_exists('external_link_count', $attrs) ? (int) $attrs['external_link_count'] : 0;

        $skipped = ! $article->countsTowardSeoScore();

        $score = $skipped ? null : SeoRuleViolationsResolver::scoreForArticle($article);
        $analyzedHash = trim((string) (
            $article->articleMetas->firstWhere('meta_key', SeoScoringRulesRegistry::META_KEY_ANALYZED_CONTENT_HASH)?->meta_value ?? ''
        ));
        $body = trim((string) ($article->body ?? ''));
        $currentHash = $body !== '' ? hash('sha256', $body) : '';
        $scoreStale = ! $skipped
            && $analyzedHash !== ''
            && $currentHash !== ''
            && ! hash_equals($analyzedHash, $currentHash);

        // content=null → format from stored violations meta only (no HTML re-score).
        $contentBonus = app(ArticleContentSeoBonusService::class)->resolveForArticle($article);

        return [
            'score' => $score,
            'score_skipped' => $skipped,
            'score_stale' => $scoreStale,
            'score_version' => trim((string) (
                $article->articleMetas->firstWhere('meta_key', SeoScoringRulesRegistry::META_KEY_SCORE_VERSION)?->meta_value ?? ''
            )) ?: null,
            'score_tone' => $skipped ? 'skipped' : ($scoreStale ? 'warning' : self::scoreTone($score)),
            'keyword' => $keyword,
            'schema' => self::schemaLabel($article),
            'links_total' => $internal + $external,
            'links_internal' => $internal,
            'links_external' => $external,
            'image_count' => app(ArticlePostImagesService::class)->countCachedForArticle($article),
            'faq_count' => $contentBonus['faq_count'],
            'featured_snippet_points' => $contentBonus['items']['featured_snippet']['points'],
            'faq_points' => $contentBonus['items']['faq']['points'],
            'edit_url' => ArticleResource::panelUrl('edit', ['record' => $article]),
        ];
    }

    /**
     * Meta-only keyword for list cells — avoids per-row Keyword::whereHas when meta present.
     * Falls back to analyzer (local DB) only when focus meta empty.
     */
    private static function resolveFocusKeywordFromMeta(SeoArticle $article): ?string
    {
        $article->loadMissing('articleMetas');
        $metaKeyword = $article->articleMetas->firstWhere('meta_key', 'seo_focus_keyword');
        if ($metaKeyword && is_string($metaKeyword->meta_value) && trim($metaKeyword->meta_value) !== '') {
            $fromMeta = Keyword::normalizeFocusPhrase($metaKeyword->meta_value);
            if ($fromMeta !== '') {
                return $fromMeta;
            }
        }

        return app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article);
    }

    public static function schemaLabel(SeoArticle $article): string
    {
        return match ((string) ($article->type ?? 'article')) {
            'product' => 'Sản phẩm (Product)',
            'page' => 'Trang (WebPage)',
            'category' => 'Danh mục (CollectionPage)',
            'product_category' => 'Danh mục sản phẩm (CollectionPage)',
            default => 'Bài viết (NewsArticle)',
        };
    }

    private static function scoreTone(?int $score): string
    {
        if ($score === null) {
            return 'muted';
        }

        return match (true) {
            $score < 50 => 'danger',
            $score < 70 => 'warning',
            default => 'success',
        };
    }
}
