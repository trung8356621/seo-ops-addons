<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoRuleViolationsResolver;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Omnichannel\Addons\Seo\Support\SeoScoringStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

final class SeoAuditKeywordFlagService
{
    public function __construct(
        private readonly SeoArticleQualityAssessmentService $assessmentService,
        private readonly SeoAuditScanService $auditScanService,
    ) {}

    /**
     * @param  Builder<SeoArticle>  $baseQuery
     * @return Builder<SeoArticle>
     */
    public function applyKeywordFlagScope(Builder $baseQuery): Builder
    {
        return (clone $baseQuery)->whereHas('linkMaps.keyword', static function (Builder $keywordQuery): void {
            $keywordQuery->whereIn('review_status', [
                KeywordReviewStatus::Warning->value,
                KeywordReviewStatus::Danger->value,
            ]);
        });
    }

    /**
     * IDs kết quả audit: default = keyword review flags; khi chọn rule/aggregate = chỉ SQL rule filter.
     *
     * @param  Builder<SeoArticle>  $baseQuery
     * @param  list<string>  $selectedRuleKeys
     * @return list<int>
     */
    public function resolveResultArticleIds(
        Builder $baseQuery,
        array $selectedRuleKeys,
        bool $filterLowSeoScore,
        bool $filterTechnicalSeoScore,
    ): array {
        $selectedRuleKeys = $this->excludeNonAuditFilterRules($selectedRuleKeys);

        $hasScoringSelection = $selectedRuleKeys !== []
            || $filterLowSeoScore
            || $filterTechnicalSeoScore;

        if (! $hasScoringSelection) {
            return $this->applyKeywordFlagScope($baseQuery)
                ->pluck('articles.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        return $this->auditScanService
            ->buildFilteredQuery($baseQuery, $selectedRuleKeys, $filterLowSeoScore, $filterTechnicalSeoScore)
            ->pluck('articles.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $selectedRuleKeys
     * @return list<string>
     */
    public function excludeNonAuditFilterRules(array $selectedRuleKeys): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $key): string => trim((string) $key), $selectedRuleKeys),
            static fn (string $key): bool => $key !== ''
                && SeoScoringRulesRegistry::isRuleFilterable($key),
        ));
    }

    /**
     * @param  Builder<SeoArticle>  $baseQuery
     * @param  list<string>  $selectedRuleKeys
     */
    public function paginateMergedResults(
        Builder $baseQuery,
        array $selectedRuleKeys,
        bool $filterLowSeoScore,
        bool $filterTechnicalSeoScore,
        int $page = 1,
        int $perPage = 15,
        ?string $sortBy = null,
        string $sortDir = 'asc',
    ): LengthAwarePaginator {
        $sortBy = $sortBy === 'score' ? 'score' : null;
        $sortDir = strtolower($sortDir) === 'desc' ? 'desc' : 'asc';
        // Root cause fix: khi user chọn rule/aggregate, KHÔNG UNION keyword_review flags.
        // Trước đây OR keyword Warning/Danger → checkbox "Thiếu từ khóa chính" vẫn ra bài đã có keyword.
        $mergedIds = $this->resolveResultArticleIds(
            $baseQuery,
            $selectedRuleKeys,
            $filterLowSeoScore,
            $filterTechnicalSeoScore,
        );
        if ($mergedIds === []) {
            return new Paginator([], 0, $perPage, $page, ['path' => request()->url(), 'query' => request()->query()]);
        }

        $articles = ArticleResource::applySeoAuditCandidateScope(
            SeoArticle::query()->whereIn('id', $mergedIds)
        )
            ->with([
                'site:id,domain',
                'articleMetas' => static function ($relation): void {
                    $relation->whereIn('meta_key', [
                        SeoScoringRulesRegistry::META_KEY_VIOLATIONS,
                        'seo_focus_keyword',
                        'wp_permalink',
                        'meta_description',
                        'seo_meta_description',
                        '_yoast_wpseo_metadesc',
                        'rank_math_description',
                    ]);
                },
                'linkMaps.keyword.reviewReason',
            ])
            ->get()
            ->keyBy('id');

        $rows = collect($mergedIds)
            ->map(function (int $articleId) use ($articles, $selectedRuleKeys, $filterLowSeoScore, $filterTechnicalSeoScore): ?array {
                $article = $articles->get($articleId);
                if (! $article instanceof SeoArticle) {
                    return null;
                }

                return $this->mapMergedArticleRow(
                    $article,
                    $selectedRuleKeys,
                    $filterLowSeoScore,
                    $filterTechnicalSeoScore,
                );
            })
            ->filter()
            ->sort(fn (array $left, array $right): int => $this->compareResultRows($left, $right, $sortBy, $sortDir))
            ->values();

        $total = $rows->count();
        $offset = max(0, ($page - 1) * $perPage);
        $pageItems = $rows->slice($offset, $perPage)->values()->all();

        return new Paginator(
            $pageItems,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareResultRows(array $left, array $right, ?string $sortBy, string $sortDir): int
    {
        if ($sortBy === 'score') {
            $scoreCompare = ((int) ($left['score'] ?? 0)) <=> ((int) ($right['score'] ?? 0));
            if ($scoreCompare !== 0) {
                return $sortDir === 'desc' ? -$scoreCompare : $scoreCompare;
            }
        } else {
            $dangerCompare = ((int) ($right['danger_count'] ?? 0)) <=> ((int) ($left['danger_count'] ?? 0));
            if ($dangerCompare !== 0) {
                return $dangerCompare;
            }

            $warningCompare = ((int) ($right['warning_count'] ?? 0)) <=> ((int) ($left['warning_count'] ?? 0));
            if ($warningCompare !== 0) {
                return $warningCompare;
            }
        }

        return strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
    }

    /**
     * @param  list<string>  $selectedRuleKeys
     * @return array<string, mixed>
     */
    private function mapMergedArticleRow(
        SeoArticle $article,
        array $selectedRuleKeys,
        bool $filterLowSeoScore,
        bool $filterTechnicalSeoScore,
    ): array {
        $violations = SeoRuleViolationsResolver::forArticle($article);
        $hasFocusKeyword = $this->auditScanService->hasCanonicalFocusKeyword($article);
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

        $keywordFlags = $this->collectKeywordFlagsForArticle($article);
        $hasKeywordFlags = $keywordFlags['warning_count'] > 0 || $keywordFlags['danger_count'] > 0;
        $focusKeyword = '';
        try {
            $focusKeyword = trim((string) (app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article) ?? ''));
        } catch (\Throwable) {
            $focusKeyword = trim((string) (
                $article->articleMetas
                    ->firstWhere('meta_key', 'seo_focus_keyword')
                    ?->meta_value ?? ''
            ));
        }

        $selectedRuleKeys = $this->excludeNonAuditFilterRules($selectedRuleKeys);

        $hasScoringSelection = $selectedRuleKeys !== []
            || $filterLowSeoScore
            || $filterTechnicalSeoScore;

        $matchesRules = false;
        if ($hasScoringSelection) {
            $matchesRules = $this->articleMatchesScoringFilters(
                $article,
                $assessment['matched_rule_keys'] ?? [],
                $selectedRuleKeys,
                $filterLowSeoScore,
                $filterTechnicalSeoScore,
                (int) ($assessment['score'] ?? 0),
            );
        }

        $sources = [];
        // keyword_review chỉ khi không chọn scoring rules (default audit surface).
        if (! $hasScoringSelection && $hasKeywordFlags) {
            $sources[] = 'keyword_review';
        }
        if ($matchesRules) {
            $sources[] = 'seo_rules';
        }

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
        if (
            in_array(SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD, $selectedRuleKeys, true)
            && ! $hasFocusKeyword
            && ! in_array(SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD, $matchedKeys, true)
        ) {
            $matchedKeys[] = SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD;
        }

        $missingLabel = (string) __('seo-content-ai::filament.articles_optimal.rule_short.missing_focus_keyword');
        $reasonLabels = array_values(array_filter(
            array_map(
                static fn (array $item): string => (string) ($item['label'] ?? ''),
                $assessment['active_violations'] ?? [],
            ),
            static function (string $label) use ($hasFocusKeyword, $missingLabel): bool {
                if ($label === '') {
                    return false;
                }
                if ($hasFocusKeyword && $label === $missingLabel) {
                    return false;
                }

                return true;
            },
        ));
        if (
            in_array(SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD, $matchedKeys, true)
            && ! in_array($missingLabel, $reasonLabels, true)
        ) {
            $reasonLabels[] = $missingLabel;
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
            'is_low_quality' => $assessment['is_low_quality'],
            'is_analyzed' => SeoScoringStatus::hasBeenAnalyzed($article),
            'warning_count' => $keywordFlags['warning_count'],
            'danger_count' => $keywordFlags['danger_count'],
            'flagged_keywords' => $keywordFlags['items'],
            'focus_keyword' => $focusKeyword,
            'has_focus_keyword' => $hasFocusKeyword,
            'audit_sources' => $sources,
            'has_keyword_flags' => $hasKeywordFlags,
            'has_seo_rule_matches' => $matchesRules,
            'updated_at' => optional($article->updated_at)->toIso8601String() ?? '',
        ];
    }

    /**
     * @return array{
     *   warning_count: int,
     *   danger_count: int,
     *   items: list<array{
     *     id: int,
     *     phrase: string,
     *     review_status: string,
     *     reason: string|null,
     *     note: string|null
     *   }>
     * }
     */
    private function collectKeywordFlagsForArticle(SeoArticle $article): array
    {
        $keywords = $article->linkMaps
            ->pluck('keyword')
            ->filter()
            ->unique('id')
            ->filter(static function (?Keyword $keyword): bool {
                if (! $keyword instanceof Keyword) {
                    return false;
                }

                $status = KeywordReviewStatus::tryFrom((string) $keyword->review_status);

                return $status?->isNegative() === true;
            });

        $items = $keywords->map(static function (Keyword $keyword): array {
            return [
                'id' => (int) $keyword->id,
                'phrase' => (string) $keyword->phrase,
                'review_status' => $keyword->isManualError() ? 'danger' : 'active',
                'reason' => null,
                'note' => null,
            ];
        })->values()->all();

        return [
            'warning_count' => 0,
            'danger_count' => $keywords->count(),
            'items' => $items,
        ];
    }

    /**
     * @param  list<string>  $matchedRuleKeys
     * @param  list<string>  $selectedRuleKeys
     */
    private function articleMatchesScoringFilters(
        SeoArticle $article,
        array $matchedRuleKeys,
        array $selectedRuleKeys,
        bool $filterLowSeoScore,
        bool $filterTechnicalSeoScore,
        int $score,
    ): bool {
        $selectedRuleKeys = array_values(array_unique(array_filter(array_map(
            static fn (mixed $key): string => trim((string) $key),
            $selectedRuleKeys,
        ), static fn (string $key): bool => $key !== '')));

        foreach ($selectedRuleKeys as $ruleKey) {
            if ($ruleKey === SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD) {
                // Canonical thiếu keyword ≠ keyword_review warning/danger.
                if (! $this->auditScanService->hasCanonicalFocusKeyword($article)) {
                    return true;
                }

                continue;
            }

            if (in_array($ruleKey, $matchedRuleKeys, true)) {
                return true;
            }
        }

        $threshold = SeoScoringRulesRegistry::AUDIT_LOW_SCORE_THRESHOLD;
        if (($filterLowSeoScore || $filterTechnicalSeoScore) && $score < $threshold) {
            return true;
        }

        return false;
    }

    private function resolveCachedPermalink(SeoArticle $article): ?string
    {
        $meta = $article->articleMetas->firstWhere('meta_key', 'wp_permalink');
        $permalink = trim((string) ($meta?->meta_value ?? ''));

        return $permalink !== '' ? $permalink : null;
    }
}
