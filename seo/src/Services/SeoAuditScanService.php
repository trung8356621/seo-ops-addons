<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoRuleViolationsResolver;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Omnichannel\Addons\Seo\Support\SeoScoringStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

final class SeoAuditScanService
{
    private const AUDIT_META_KEYS = [
        SeoScoringRulesRegistry::META_KEY_VIOLATIONS,
        'wp_permalink',
        'meta_description',
        'seo_meta_description',
        '_yoast_wpseo_metadesc',
        'rank_math_description',
    ];

    public function __construct(
        private readonly SeoArticleQualityAssessmentService $assessmentService,
    ) {}

    /**
     * @param  Builder<SeoArticle>  $baseQuery
     * @param  list<string>  $selectedRuleKeys
     */
    public function paginateResults(
        Builder $baseQuery,
        array $selectedRuleKeys,
        bool $filterLowSeoScore,
        bool $filterTechnicalSeoScore,
        int $page = 1,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $selectedRuleKeys = $this->normalizeSelectedRuleKeys($selectedRuleKeys);
        $query = $this->buildFilteredQuery(
            $baseQuery,
            $selectedRuleKeys,
            $filterLowSeoScore,
            $filterTechnicalSeoScore,
        );

        $this->ensureSeoProfileJoin($query);

        $query->select([
            'articles.id',
            'articles.site_id',
            'articles.title',
            'audit_sap.seo_score as seo_score',
            'articles.slug',
            'articles.updated_at',
        ])->with([
            'site:id,domain',
            'articleMetas' => static function ($relation): void {
                $relation->whereIn('meta_key', self::AUDIT_META_KEYS);
            },
        ]);

        /** @var LengthAwarePaginator<int, SeoArticle> $paginator */
        $paginator = $query->paginate(
            perPage: $perPage,
            page: $page,
        );

        $rows = $paginator->getCollection()
            ->map(fn (SeoArticle $article): array => $this->mapArticleRow($article))
            ->values()
            ->all();

        return new Paginator(
            $rows,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * Map already-loaded articles to audit suggestion rows (no re-query).
     *
     * @param  \Illuminate\Support\Collection<int, SeoArticle>|list<SeoArticle>  $articles
     * @return list<array<string, mixed>>
     */
    public function mapLoadedArticles(iterable $articles): array
    {
        $rows = [];
        foreach ($articles as $article) {
            if (! $article instanceof SeoArticle) {
                continue;
            }
            $article->loadMissing([
                'site:id,domain',
                'seoProfile',
                'articleMetas' => static function ($relation): void {
                    $relation->whereIn('meta_key', self::AUDIT_META_KEYS);
                },
            ]);
            $rows[] = $this->mapArticleRow($article);
        }

        return $rows;
    }

    /**
     * @param  Builder<SeoArticle>  $baseQuery
     * @return array{
     *   total: int,
     *   analyzed: int,
     *   pending: int,
     *   processing: int,
     *   failed: int,
     *   remaining: int
     * }
     */
    public function cacheStatusCounts(Builder $baseQuery): array
    {
        $total = (clone $baseQuery)->count();

        $analyzed = (clone $baseQuery)->where(function (Builder $query): void {
            $query->whereHas('articleMetas', static function (Builder $meta): void {
                $meta->where('meta_key', SeoScoringRulesRegistry::META_KEY_VIOLATIONS);
            })->orWhereHas('seoProfile', static function (Builder $profile): void {
                $profile->whereNotNull('seo_score');
            });
        })->count();

        $pending = (clone $baseQuery)->whereHas('articleMetas', static function (Builder $meta): void {
            $meta->where('meta_key', SeoScoringStatus::META_KEY_STATUS)
                ->where('meta_value', SeoScoringStatus::STATUS_PENDING);
        })->count();

        $processing = (clone $baseQuery)->whereHas('articleMetas', static function (Builder $meta): void {
            $meta->where('meta_key', SeoScoringStatus::META_KEY_STATUS)
                ->where('meta_value', SeoScoringStatus::STATUS_PROCESSING);
        })->count();

        $failed = (clone $baseQuery)->whereHas('articleMetas', static function (Builder $meta): void {
            $meta->where('meta_key', SeoScoringStatus::META_KEY_STATUS)
                ->where('meta_value', SeoScoringStatus::STATUS_FAILED);
        })->count();

        return [
            'total' => $total,
            'analyzed' => $analyzed,
            'pending' => $pending,
            'processing' => $processing,
            'failed' => $failed,
            'remaining' => max(0, $total - $analyzed - $pending - $processing),
        ];
    }

    /**
     * @param  list<string>  $selectedRuleKeys
     */
    public function isMissingFocusKeywordOnly(
        array $selectedRuleKeys,
        bool $filterLowSeoScore,
        bool $filterTechnicalSeoScore,
    ): bool {
        $selectedRuleKeys = $this->normalizeSelectedRuleKeys($selectedRuleKeys);

        return $selectedRuleKeys === [SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD]
            && ! $filterLowSeoScore
            && ! $filterTechnicalSeoScore;
    }

    /**
     * Canonical focus keyword — ONE contract with SeoAnalyzerService::resolveFocusKeywordForArticle():
     * 1) article_meta seo_focus_keyword (normalized)
     * 2) Keyword MainArticleId phrase fallback
     * 3) else missing
     *
     * Warning/danger keyword_review ≠ missing_focus_keyword.
     */
    public function hasCanonicalFocusKeyword(SeoArticle $article): bool
    {
        try {
            return trim((string) (app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article) ?? '')) !== '';
        } catch (\Throwable) {
            // Unit/SQLite may lack keyword tables — fail closed to meta-only normalize.
            $article->loadMissing('articleMetas');
            $metaValue = Keyword::normalizeFocusPhrase((string) (
                $article->articleMetas->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value ?? ''
            ));

            return $metaValue !== '';
        }
    }

    /**
     * @param  Builder<SeoArticle>  $baseQuery
     * @param  list<string>  $selectedRuleKeys
     */
    public function buildFilteredQuery(
        Builder $baseQuery,
        array $selectedRuleKeys,
        bool $filterLowSeoScore,
        bool $filterTechnicalSeoScore,
    ): Builder {
        $selectedRuleKeys = $this->normalizeSelectedRuleKeys($selectedRuleKeys);
        $query = clone $baseQuery;

        $hasScoringSelection = $selectedRuleKeys !== [] || $filterLowSeoScore || $filterTechnicalSeoScore;
        $missingKeywordOnly = $this->isMissingFocusKeywordOnly($selectedRuleKeys, $filterLowSeoScore, $filterTechnicalSeoScore);

        // Fast path: chỉ thiếu keyword — không bắt buộc đã có cache violations/seo_score.
        if (! $hasScoringSelection || ! $missingKeywordOnly) {
            $query->where(function (Builder $analyzedScope): void {
                $analyzedScope->whereHas('articleMetas', static function (Builder $meta): void {
                    $meta->where('meta_key', SeoScoringRulesRegistry::META_KEY_VIOLATIONS);
                })->orWhereHas('seoProfile', static function (Builder $profile): void {
                    $profile->whereNotNull('seo_score');
                });
            });
        }

        if (! $hasScoringSelection) {
            return $query;
        }

        $threshold = SeoScoringRulesRegistry::AUDIT_LOW_SCORE_THRESHOLD;

        $enabledRuleKeys = array_values(array_filter(
            $selectedRuleKeys,
            static fn (string $ruleKey): bool => SeoScoringRulesRegistry::isRuleEnabled($ruleKey),
        ));

        if ($enabledRuleKeys === [] && ! $filterLowSeoScore && ! $filterTechnicalSeoScore) {
            return $query->whereRaw('0 = 1');
        }

        $query->where(function (Builder $orGroup) use ($enabledRuleKeys, $filterLowSeoScore, $filterTechnicalSeoScore, $threshold): void {
            foreach ($enabledRuleKeys as $ruleKey) {
                if ($ruleKey === SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD) {
                    $orGroup->orWhere(function (Builder $missingKeyword): void {
                        $this->applyMissingFocusKeywordScope($missingKeyword);
                    });

                    continue;
                }

                $orGroup->orWhereHas('articleMetas', static function (Builder $meta) use ($ruleKey): void {
                    $meta->where('meta_key', SeoScoringRulesRegistry::META_KEY_VIOLATIONS)
                        ->whereRaw(
                            '(JSON_VALID(meta_value) = 1 AND JSON_CONTAINS(meta_value, ?))',
                            [json_encode($ruleKey, JSON_THROW_ON_ERROR)]
                        );
                });
            }

            if ($filterLowSeoScore || $filterTechnicalSeoScore) {
                $orGroup->orWhereHas('seoProfile', static function (Builder $profile) use ($threshold): void {
                    $profile->whereNotNull('seo_score')->where('seo_score', '<', $threshold);
                });
            }
        });

        return $query;
    }

    /**
     * @param  Builder<SeoArticle>  $query
     */
    public function applyMissingFocusKeywordScope(Builder $query): void
    {
        $query->whereNot(function (Builder $hasKeyword): void {
            $hasKeyword
                ->whereHas('articleMetas', static function (Builder $meta): void {
                    $meta->where('meta_key', 'seo_focus_keyword')
                        ->whereNotNull('meta_value')
                        ->where('meta_value', '!=', '')
                        ->whereRaw("TRIM(meta_value) <> ''");
                })
                ->orWhereExists(static function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('keyword_meta')
                        ->join('keywords', 'keywords.id', '=', 'keyword_meta.keyword_id')
                        ->whereColumn('keyword_meta.meta_value', 'articles.id')
                        ->where('keyword_meta.meta_key', KeywordMetaKey::MainArticleId->value)
                        ->whereNotNull('keywords.phrase')
                        ->where('keywords.phrase', '!=', '')
                        ->whereRaw("TRIM(keywords.phrase) <> ''");
                });
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function mapArticleRow(SeoArticle $article): array
    {
        $violations = SeoRuleViolationsResolver::forArticle($article);
        $hasFocusKeyword = $this->hasCanonicalFocusKeyword($article);
        if ($hasFocusKeyword) {
            $violations = array_values(array_filter(
                $violations,
                static fn (mixed $key): bool => (string) $key !== SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD
                    && (string) $key !== 'seo.missing_focus_keyword',
            ));
        }

        $assessment = $this->assessmentService->assessFromAnalysis([
            'violations' => $violations,
            'seo_score' => $article->seoProfile?->seo_score !== null ? (int) round((float) $article->seoProfile->seo_score) : null,
        ]);

        $matchedKeys = array_values(array_map(
            static fn (mixed $key): string => (string) $key,
            $assessment['matched_rule_keys'] ?? [],
        ));
        if ($hasFocusKeyword) {
            $matchedKeys = array_values(array_filter(
                $matchedKeys,
                static fn (string $key): bool => $key !== SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD,
            ));
        }

        $reasonLabels = array_values(array_filter(
            array_map(
                static fn (array $item): string => (string) ($item['label'] ?? ''),
                $assessment['active_violations'] ?? [],
            ),
            static function (string $label) use ($hasFocusKeyword): bool {
                if ($label === '') {
                    return false;
                }
                if ($hasFocusKeyword && $label === (string) __('seo-content-ai::filament.articles_optimal.rule_short.missing_focus_keyword')) {
                    return false;
                }

                return true;
            },
        ));

        $focusKeyword = '';
        try {
            $focusKeyword = trim((string) (app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article) ?? ''));
        } catch (\Throwable) {
            $focusKeyword = Keyword::normalizeFocusPhrase((string) (
                $article->articleMetas->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value ?? ''
            ));
        }

        return [
            'id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0),
            'title' => (string) ($article->title ?? ''),
            'domain' => (string) ($article->site?->domain ?? ''),
            'permalink' => $this->resolveCachedPermalink($article),
            'edit_url' => ArticleResource::getUrl('edit', ['record' => $article]),
            'score' => $assessment['score'],
            'technical_score' => $assessment['technical_score'],
            'matched_rule_keys' => $matchedKeys,
            'violations' => $matchedKeys,
            'reason_keys' => $matchedKeys,
            'reason_labels' => $reasonLabels,
            'focus_keyword' => $focusKeyword,
            'has_focus_keyword' => $hasFocusKeyword,
            'is_low_quality' => $assessment['is_low_quality'],
            'is_analyzed' => SeoScoringStatus::hasBeenAnalyzed($article),
        ];
    }

    private function resolveCachedPermalink(SeoArticle $article): ?string
    {
        $meta = $article->articleMetas->firstWhere('meta_key', 'wp_permalink');
        $permalink = trim((string) ($meta?->meta_value ?? ''));

        return $permalink !== '' ? $permalink : null;
    }

    /**
     * @param  Builder<SeoArticle>  $query
     */
    private function ensureSeoProfileJoin(Builder $query): void
    {
        foreach ($query->getQuery()->joins ?? [] as $join) {
            $table = strtolower((string) $join->table);
            if ($table === 'audit_sap' || str_ends_with($table, ' as audit_sap')) {
                return;
            }
        }

        $query->leftJoin('seo_article_profiles as audit_sap', 'audit_sap.article_id', '=', 'articles.id');
    }

    /**
     * @param  list<string>  $selectedRuleKeys
     * @return list<string>
     */
    private function normalizeSelectedRuleKeys(array $selectedRuleKeys): array
    {
        return array_values(array_unique(array_filter(array_map(
            static function (mixed $key): ?string {
                if (! is_string($key) && ! is_numeric($key)) {
                    return null;
                }

                $normalized = trim((string) $key);

                return $normalized !== '' ? $normalized : null;
            },
            $selectedRuleKeys,
        ))));
    }
}
