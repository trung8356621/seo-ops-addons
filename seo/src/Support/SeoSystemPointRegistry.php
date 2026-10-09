<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Content\Support\ArticleSeoInventoryPolicy;
use Omnichannel\Addons\Seo\Services\FocusKeywordCoverageQuery;
use Omnichannel\Addons\Seo\Services\SeoAuditScanService;

/**
 * Canonical SEO System Point codes (1–10).
 *
 * A System Point classifies a special state. It is not an SEO quality score.
 * Persisted seo_score is left unchanged: local data already has legitimate
 * quality scores inside 1–10, so codes must not be stamped into that column.
 */
final class SeoSystemPointRegistry
{
    public const CODE_WP_PROTECTED = 1;

    public const CODE_PAGE = 2;

    public const CODE_MISSING_FOCUS_KEYWORD = 3;

    public const RESERVED_FROM = 4;

    public const RESERVED_THROUGH = 10;

    public const QUALITY_MIN = 11;

    public const QUALITY_MAX = 100;

    /**
     * @return array<int, string>
     */
    public static function meanings(): array
    {
        return [
            self::CODE_WP_PROTECTED => 'WordPress structural/special block — protected',
            self::CODE_PAGE => 'WordPress Page',
            self::CODE_MISSING_FOCUS_KEYWORD => 'Missing Focus Keyword',
        ];
    }

    public static function isSystemPointCode(int $code): bool
    {
        return $code >= self::CODE_WP_PROTECTED && $code <= self::RESERVED_THROUGH;
    }

    public static function isReservedCode(int $code): bool
    {
        return $code >= self::RESERVED_FROM && $code <= self::RESERVED_THROUGH;
    }

    /**
     * Priority: protected WP object, Page, missing canonical Focus Keyword.
     * Score 0 with a keyword stays unclassified (null), not code 3.
     * Numeric seo_score is intentionally ignored.
     */
    public static function resolve(SeoArticle $article, ?bool $hasCanonicalFocusKeyword = null): ?int
    {
        $article->loadMissing('articleMetas');
        $classification = ArticleContentClassification::for($article);

        if (ArticleSeoInventoryPolicy::isSystemWpPostType($classification->wpPostType())) {
            return self::CODE_WP_PROTECTED;
        }

        if ($classification->isTerm()) {
            return null;
        }

        if ($classification->contentType() === ContentType::Page) {
            return self::CODE_PAGE;
        }

        $hasKeyword = $hasCanonicalFocusKeyword ?? app(SeoAuditScanService::class)->hasCanonicalFocusKeyword($article);
        if (! $hasKeyword) {
            return self::CODE_MISSING_FOCUS_KEYWORD;
        }

        return null;
    }

    /**
     * @return array{system_point: int|null, quality_score: int|null, rankable: bool}
     */
    public static function present(?int $systemPoint, ?int $calculatedScore): array
    {
        return [
            'system_point' => $systemPoint,
            'quality_score' => $systemPoint === null ? $calculatedScore : null,
            'rankable' => $systemPoint === null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function overlayAuditRow(array $row, ?int $systemPoint): array
    {
        $score = array_key_exists('score', $row) && $row['score'] !== null ? (int) $row['score'] : null;
        $presented = self::present($systemPoint, $score);
        $row['system_point'] = $presented['system_point'];
        $row['quality_score'] = $presented['quality_score'];
        $row['rankable'] = $presented['rankable'];
        if ($systemPoint !== null) {
            $row['is_low_quality'] = false;
        }

        return $row;
    }

    public static function isRankable(SeoArticle $article, ?bool $hasCanonicalFocusKeyword = null): bool
    {
        if (! $article->countsTowardSeoScore()) {
            return false;
        }

        return self::resolve($article, $hasCanonicalFocusKeyword) === null;
    }

    /**
     * Ordinary SEO quality ranking/averages.
     * Does not hide rows from unfiltered inventory queries.
     *
     * @param  Builder<SeoArticle>  $query
     * @return Builder<SeoArticle>
     */
    public static function scopeOrdinaryQuality(Builder $query): Builder
    {
        self::scopeExcludePages($query);
        self::scopeHasCanonicalFocusKeyword($query);

        return ArticleSeoInventoryPolicy::scopeCandidates($query);
    }

    /**
     * @param  Builder<SeoArticle>  $query
     */
    private static function scopeExcludePages(Builder $query): void
    {
        $query->where(function (Builder $keep): void {
            $keep->whereDoesntHave('articleMetas', static function (Builder $meta): void {
                $meta->where('meta_key', ArticleContentClassification::META_CONTENT_TYPE)
                    ->whereRaw("LOWER(TRIM(meta_value)) = 'page'");
            })->whereDoesntHave('articleMetas', static function (Builder $meta): void {
                $meta->where('meta_key', ArticleContentClassification::META_WP_POST_TYPE)
                    ->whereRaw("LOWER(TRIM(meta_value)) = 'page'");
            });
        });
    }

    /**
     * @param  Builder<SeoArticle>  $query
     */
    private static function scopeHasCanonicalFocusKeyword(Builder $query): void
    {
        $connection = $query->getModel()->getConnectionName() ?: 'omi_seo_ai';
        if (
            Schema::connection($connection)->hasTable('keyword_meta')
            && Schema::connection($connection)->hasTable('keywords')
        ) {
            app(FocusKeywordCoverageQuery::class)->applyHasEffectiveFocusScope($query);

            return;
        }

        $query->whereHas('articleMetas', static function (Builder $meta): void {
            $meta->where('meta_key', 'seo_focus_keyword')
                ->whereNotNull('meta_value')
                ->where('meta_value', '!=', '')
                ->whereRaw("TRIM(meta_value) <> ''");
        });
    }
}
