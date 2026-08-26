<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Services\ArticleSeoAuditSkipService;
use Omnichannel\Addons\Content\Services\ContentLanguageLegacyRepair;
use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectSuggestionDecision;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Seo\Services\SeoAuditScanService;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;
use App\Models\Site;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

/**
 * Canonical read path: SEO Audit evidence → article-grouped Existing Content suggestions.
 * Does not scan/crawl; uses stored SEO state via SeoAuditScanService.
 */
final class SeoAuditExistingContentSuggestionService
{
    public const SOURCE_TYPE = SeoContentProjectSuggestionDecision::SOURCE_SEO_AUDIT;

    public const ACTION_REWRITE = 'rewrite';

    public const ACTION_IMPROVE = 'improve';

    public const STATE_AVAILABLE = 'available';

    public const STATE_PLANNED = 'already_planned';

    public const STATE_PLANNED_OTHER = 'planned_other_project';

    public const STATE_DISMISSED = 'dismissed';

    public function __construct(
        private readonly SeoAuditScanService $auditScan,
        private readonly SeoAuditSuggestionDecisionService $decisions,
        private readonly ArticleSeoAuditSkipService $skipService = new ArticleSeoAuditSkipService,
    ) {}

    /**
     * @param  array{
     *   search?: string,
     *   score_max?: int|null,
     *   score_min?: int|null,
     *   issue_keys?: list<string>,
     *   suggested_action?: string,
     *   post_type?: string,
     *   post_type_mode?: string,
     *   taxonomy?: string,
     *   term_id?: int|null,
     *   show_planned?: bool,
     *   show_dismissed?: bool,
     *   only_with_issues?: bool,
     *   language_scope?: string,
     *   language?: string|null,
     *   exclude_taxonomy_archives?: bool,
     *   exclude_skip_seo_audit?: bool,
     *   show_globally_skipped?: bool,
     *   state?: string
     * }  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(SeoProject $project, array $filters = [], int $page = 1, int $perPage = 25): LengthAwarePaginator
    {
        $siteId = (int) ($project->site_id ?? 0);
        if ($siteId <= 0) {
            return new Paginator([], 0, $perPage, $page);
        }

        $filters = SeoAuditSuggestionFilterSet::normalize($filters);

        $base = SeoArticle::query()
            ->where('articles.site_id', $siteId)
            ->notContentArchived();

        $this->applyLanguageFilter($base, $project, $filters);
        $this->applyEntityAndPostTypeScopes($base, $filters);
        $this->applyTaxonomyTermScope($base, $filters);
        $this->applySkipSeoAuditScope($base, $filters);
        $this->applyStateSqlScope($base, $project, $filters);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $base->where(function (Builder $q) use ($like): void {
                $q->where('articles.title', 'like', $like)
                    ->orWhere('articles.slug', 'like', $like);
            });
        }

        $issueKeys = $this->normalizeIssueKeys($filters['issue_keys'] ?? []);
        $filterLow = (bool) ($filters['filter_low_score'] ?? false);
        $scoreMax = isset($filters['score_max']) && $filters['score_max'] !== null && $filters['score_max'] !== ''
            ? (int) $filters['score_max']
            : null;
        $scoreMin = isset($filters['score_min']) && $filters['score_min'] !== null && $filters['score_min'] !== ''
            ? (int) $filters['score_min']
            : null;

        if ($scoreMax !== null && $scoreMax < SeoScoringRulesRegistry::AUDIT_LOW_SCORE_THRESHOLD) {
            $filterLow = true;
        }

        $onlyWithIssues = (bool) ($filters['only_with_issues'] ?? true);
        if ($onlyWithIssues && $issueKeys === [] && ! $filterLow && $scoreMax === null) {
            $filterLow = true;
        }

        $query = $this->auditScan->buildFilteredQuery($base, $issueKeys, $filterLow, false);

        if ($scoreMax !== null || $scoreMin !== null) {
            $query->whereHas('seoProfile', function (Builder $profile) use ($scoreMax, $scoreMin): void {
                $profile->whereNotNull('seo_score');
                if ($scoreMax !== null) {
                    $profile->where('seo_score', '<=', $scoreMax);
                }
                if ($scoreMin !== null) {
                    $profile->where('seo_score', '>=', $scoreMin);
                }
            });
        }

        $this->ensureSeoProfileJoin($query);
        $query->select([
            'articles.id',
            'articles.site_id',
            'articles.title',
            'articles.slug',
            'articles.type',
            'articles.updated_at',
            'audit_sap.seo_score as seo_score',
        ])->with([
            'site:id,domain',
            'articleMetas' => static function ($relation): void {
                $relation->whereIn('meta_key', [
                    SeoScoringRulesRegistry::META_KEY_VIOLATIONS,
                    'wp_permalink',
                    'seo_focus_keyword',
                    'wp_post_type',
                    ArticleSeoAuditSkipService::META_KEY,
                ]);
            },
            'seoProfile:article_id,seo_score',
        ]);

        $query->orderByRaw('CASE WHEN audit_sap.seo_score IS NULL THEN 1 ELSE 0 END')
            ->orderBy('audit_sap.seo_score')
            ->orderByDesc('articles.updated_at')
            ->orderBy('articles.id');

        /** @var LengthAwarePaginator<int, SeoArticle> $raw */
        $raw = $query->paginate(perPage: $perPage, page: $page);

        $articles = $raw->getCollection();
        $mapped = $this->mapCandidates($project, $articles);

        $actionFilter = trim((string) ($filters['suggested_action'] ?? ''));

        $filtered = $mapped->filter(function (array $row) use ($actionFilter): bool {
            if ($actionFilter !== '' && (string) ($row['suggested_action'] ?? '') !== $actionFilter) {
                return false;
            }

            return true;
        })->values();

        return new Paginator(
            $filtered->all(),
            $raw->total(),
            $raw->perPage(),
            $raw->currentPage(),
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * Count eligible candidates under the same filters as Fill (available-only when state=available).
     *
     * @param  array<string, mixed>  $filters
     */
    public function countMatched(SeoProject $project, array $filters = []): int
    {
        $filters = SeoAuditSuggestionFilterSet::normalize($filters);
        $paginator = $this->paginate($project, $filters, 1, 1);

        return (int) $paginator->total();
    }

    /**
     * Eligible fill set (available only), sorted deterministically, limited.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function eligibleForFill(SeoProject $project, array $filters, int $limit): array
    {
        $filters = SeoAuditSuggestionFilterSet::normalize($filters);
        $filters['state'] = self::STATE_AVAILABLE;
        $filters['show_planned'] = false;
        $filters['show_dismissed'] = false;
        $filters['only_with_issues'] = $filters['only_with_issues'] ?? true;

        $collected = [];
        $page = 1;
        $perPage = min(100, max(25, $limit));

        while (count($collected) < $limit) {
            $paginator = $this->paginate($project, $filters, $page, $perPage);
            $batch = collect($paginator->items())
                ->filter(static fn (array $row): bool => (string) ($row['state'] ?? '') === self::STATE_AVAILABLE)
                ->values()
                ->all();

            if ($batch === []) {
                break;
            }

            foreach ($batch as $row) {
                $collected[] = $row;
                if (count($collected) >= $limit) {
                    break 2;
                }
            }

            if ($page >= $paginator->lastPage()) {
                break;
            }
            $page++;
        }

        usort($collected, static function (array $a, array $b): int {
            $sev = ((int) ($b['severity'] ?? 0)) <=> ((int) ($a['severity'] ?? 0));
            if ($sev !== 0) {
                return $sev;
            }
            $scoreA = $a['seo_score'] ?? PHP_INT_MAX;
            $scoreB = $b['seo_score'] ?? PHP_INT_MAX;
            $scoreCmp = ((int) $scoreA) <=> ((int) $scoreB);
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }
            $issues = ((int) ($b['issue_count'] ?? 0)) <=> ((int) ($a['issue_count'] ?? 0));
            if ($issues !== 0) {
                return $issues;
            }

            return ((int) ($a['article_id'] ?? 0)) <=> ((int) ($b['article_id'] ?? 0));
        });

        return array_slice($collected, 0, $limit);
    }

    /**
     * @param  Collection<int, SeoArticle>  $articles
     * @return Collection<int, array<string, mixed>>
     */
    public function mapCandidates(SeoProject $project, Collection $articles): Collection
    {
        if ($articles->isEmpty()) {
            return collect();
        }

        $projectId = (int) $project->getKey();
        $articleIds = $articles->map(static fn (SeoArticle $a): int => (int) $a->id)->all();

        $taskRows = SeoProjectTask::query()
            ->whereIn('article_id', $articleIds)
            ->whereNull('archived_at')
            ->get(['id', 'project_id', 'article_id', 'type']);

        $taskByArticle = [];
        foreach ($taskRows as $task) {
            $aid = (int) $task->article_id;
            if ($aid > 0 && ! isset($taskByArticle[$aid])) {
                $taskByArticle[$aid] = $task;
            }
        }

        $projectNames = [];
        $otherProjectIds = $taskRows
            ->pluck('project_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0 && $id !== $projectId)
            ->unique()
            ->values()
            ->all();
        if ($otherProjectIds !== []) {
            $projectNames = SeoProject::query()
                ->whereIn('id', $otherProjectIds)
                ->pluck('name', 'id')
                ->all();
        }

        $dismissed = $this->decisions->dismissedArticleIds($project);

        $auditById = [];
        foreach ($this->auditScan->mapLoadedArticles($articles) as $auditRow) {
            $aid = (int) ($auditRow['id'] ?? 0);
            if ($aid > 0) {
                $auditById[$aid] = $auditRow;
            }
        }

        return $articles->map(function (SeoArticle $article) use (
            $projectId,
            $taskByArticle,
            $projectNames,
            $dismissed,
            $auditById,
        ): array {
            $id = (int) $article->id;
            $audit = $auditById[$id] ?? [];
            $score = isset($audit['score']) ? (int) $audit['score'] : (
                $article->seo_score !== null ? (int) round((float) $article->seo_score) : null
            );
            $reasonKeys = array_values(array_map('strval', $audit['reason_keys'] ?? $audit['matched_rule_keys'] ?? []));
            $reasonLabels = array_values(array_map('strval', $audit['reason_labels'] ?? []));
            $issueCount = count($reasonKeys);
            $suggested = $this->suggestAction($score, $reasonKeys, (bool) ($audit['is_low_quality'] ?? false));
            $severity = $this->severity($score, $issueCount, $suggested);

            $state = self::STATE_AVAILABLE;
            $plannedProjectId = null;
            $plannedProjectName = null;
            $addDisabled = false;

            if (isset($dismissed[$id])) {
                $state = self::STATE_DISMISSED;
                $addDisabled = true;
            }

            $task = $taskByArticle[$id] ?? null;
            if ($task instanceof SeoProjectTask) {
                $plannedProjectId = (int) $task->project_id;
                if ($plannedProjectId === $projectId) {
                    $state = self::STATE_PLANNED;
                    $addDisabled = true;
                } else {
                    $state = self::STATE_PLANNED_OTHER;
                    $plannedProjectName = (string) ($projectNames[$plannedProjectId] ?? ('Project #'.$plannedProjectId));
                    $addDisabled = true;
                }
            }

            $permalink = isset($audit['permalink']) ? (string) $audit['permalink'] : null;
            $title = (string) ($audit['title'] ?? $article->title ?? '');
            $postType = $this->resolvePostTypeSlug($article);

            return [
                'article_id' => $id,
                'site_id' => (int) ($article->site_id ?? 0),
                'title' => $title,
                'url' => $permalink,
                'permalink' => $permalink,
                'post_type' => $postType,
                'seo_score' => $score,
                'focus_keyword' => (string) ($audit['focus_keyword'] ?? ''),
                'issue_count' => $issueCount,
                'severity' => $severity,
                'issues' => array_map(
                    static fn (int $i): array => [
                        'key' => $reasonKeys[$i] ?? '',
                        'label' => $reasonLabels[$i] ?? ($reasonKeys[$i] ?? ''),
                    ],
                    array_keys($reasonKeys),
                ),
                'reason_codes' => $reasonKeys,
                'reason_labels' => $reasonLabels,
                'recommendation_summary' => $this->recommendationSummary($suggested, $reasonLabels),
                'suggested_action' => $suggested,
                'state' => $state,
                'already_planned' => in_array($state, [self::STATE_PLANNED, self::STATE_PLANNED_OTHER], true),
                'dismissed' => $state === self::STATE_DISMISSED,
                'planned_project_id' => $plannedProjectId,
                'planned_project_name' => $plannedProjectName,
                'add_disabled' => $addDisabled,
                'edit_url' => (string) ($audit['edit_url'] ?? ''),
                'public_url' => $permalink !== null && $permalink !== '' ? $permalink : null,
                'check_index_url' => SeoAuditCheckIndexUrl::forCanonicalUrl($permalink),
                'updated_at' => $article->updated_at?->toIso8601String(),
            ];
        })->values();
    }

    /**
     * @param  list<string>  $reasonKeys
     */
    public function suggestAction(?int $score, array $reasonKeys, bool $isLowQuality): string
    {
        $rewriteSignals = [
            SeoScoringRulesRegistry::KEY_CONTENT_LENGTH_LOW,
        ];

        $hasRewriteSignal = $isLowQuality
            || ($score !== null && $score < SeoScoringRulesRegistry::AUDIT_LOW_SCORE_THRESHOLD)
            || count(array_intersect($reasonKeys, $rewriteSignals)) > 0;

        if ($hasRewriteSignal && $score !== null && $score < 40) {
            return self::ACTION_REWRITE;
        }

        if ($hasRewriteSignal && count($reasonKeys) >= 4) {
            return self::ACTION_REWRITE;
        }

        // Safe default: improve (manual-only) unless clearly weak content.
        if ($hasRewriteSignal && $score !== null && $score < 50 && count($reasonKeys) >= 3) {
            return self::ACTION_REWRITE;
        }

        return self::ACTION_IMPROVE;
    }

    /**
     * @param  list<string>  $reasonLabels
     */
    public function recommendationSummary(string $action, array $reasonLabels): string
    {
        $top = array_slice(array_values(array_filter($reasonLabels)), 0, 3);
        if ($top === []) {
            return $action === self::ACTION_REWRITE
                ? 'Rewrite weak content based on SEO audit.'
                : 'Improve SEO issues from audit.';
        }

        $joined = implode(', ', $top);

        return $action === self::ACTION_REWRITE
            ? 'Rewrite: '.$joined.'.'
            : 'Improve: '.$joined.'.';
    }

    private function severity(?int $score, int $issueCount, string $action): int
    {
        $base = $action === self::ACTION_REWRITE ? 80 : 40;
        $scorePart = $score === null ? 20 : max(0, 100 - $score);
        $issuePart = min(40, $issueCount * 8);

        return min(100, $base + (int) round($scorePart * 0.3) + $issuePart);
    }

    /**
     * @param  mixed  $keys
     * @return list<string>
     */
    private function normalizeIssueKeys(mixed $keys): array
    {
        if (! is_array($keys)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static function (mixed $key): ?string {
                $normalized = trim((string) $key);

                return $normalized !== '' ? $normalized : null;
            },
            $keys,
        ))));
    }

    /**
     * @param  Builder<SeoArticle>  $base
     * @param  array<string, mixed>  $filters
     */
    private function applyLanguageFilter(Builder $base, SeoProject $project, array $filters): void
    {
        $language = trim((string) ($filters['language'] ?? ''));
        if ($language !== '' && strtolower($language) !== 'all') {
            $code = ArticleLanguageCode::normalize($language);
            if ($code !== '') {
                $variants = ContentLanguageLegacyRepair::knownStoredVariants($code);
                $base->whereIn('articles.language', $variants !== [] ? $variants : [$code]);
            }

            return;
        }

        $scope = strtolower(trim((string) ($filters['language_scope'] ?? 'primary')));
        if ($scope === 'all') {
            return;
        }

        $site = $project->site;
        if (! $site instanceof Site) {
            $siteId = (int) ($project->site_id ?? 0);
            $site = $siteId > 0 ? Site::query()->find($siteId) : null;
        }
        if (! $site instanceof Site) {
            return;
        }

        $primary = app(SitePrimaryLanguageService::class);
        $code = $primary->resolvePrimaryLanguage($site) ?? $primary->seedCandidate($site);
        if ($code === null) {
            return;
        }

        $variants = ContentLanguageLegacyRepair::knownStoredVariants($code);
        $base->whereIn('articles.language', $variants !== [] ? $variants : [$code]);
    }

    /**
     * Post-like entities only by default; optional specific post_type; exclude page by default.
     *
     * @param  Builder<SeoArticle>  $base
     * @param  array<string, mixed>  $filters
     */
    private function applyEntityAndPostTypeScopes(Builder $base, array $filters): void
    {
        if ((bool) ($filters['exclude_taxonomy_archives'] ?? true)) {
            $base->where(function (Builder $scopeQuery): void {
                $scopeQuery
                    ->whereIn('articles.type', ['article', 'product'])
                    ->orWhere(function (Builder $sub): void {
                        $sub->whereNull('articles.type')->orWhere('articles.type', '');
                    });
            });
        }

        $mode = (string) ($filters['post_type_mode'] ?? SeoAuditSuggestionFilterSet::POST_TYPE_MODE_ALL_EXCEPT_PAGE);
        $postType = strtolower(trim((string) ($filters['post_type'] ?? '')));

        if ($mode === SeoAuditSuggestionFilterSet::POST_TYPE_MODE_SPECIFIC && $postType !== '') {
            ArticleResource::applyPostTypeFilterScope($base, $postType);

            return;
        }

        if ($mode === SeoAuditSuggestionFilterSet::POST_TYPE_MODE_ALL) {
            return;
        }

        // Default: all except page.
        $base->where(function (Builder $scopeQuery): void {
            $scopeQuery
                ->whereDoesntHave('articleMetas', static function (Builder $metaQ): void {
                    $metaQ->where('meta_key', 'wp_post_type')->where('meta_value', 'page');
                })
                ->where(function (Builder $typeQ): void {
                    $typeQ->whereNull('articles.type')
                        ->orWhere('articles.type', '')
                        ->orWhereNotIn('articles.type', ['page']);
                });
        });
    }

    /**
     * @param  Builder<SeoArticle>  $base
     * @param  array<string, mixed>  $filters
     */
    private function applyTaxonomyTermScope(Builder $base, array $filters): void
    {
        $termId = isset($filters['term_id']) ? (int) $filters['term_id'] : 0;
        if ($termId <= 0) {
            return;
        }

        $base->whereIn('articles.id', function ($subQuery) use ($termId): void {
            $subQuery->select('article_id')
                ->from('article_meta')
                ->where('meta_key', 'category_ids')
                ->whereRaw(
                    'FIND_IN_SET(?, REPLACE(REPLACE(REPLACE(`meta_value`, " ", ""), "[", ""), "]", "")) > 0',
                    [$termId],
                );
        });
    }

    /**
     * @param  Builder<SeoArticle>  $base
     * @param  array<string, mixed>  $filters
     */
    private function applySkipSeoAuditScope(Builder $base, array $filters): void
    {
        if ((bool) ($filters['show_globally_skipped'] ?? false)) {
            return;
        }

        if (! (bool) ($filters['exclude_skip_seo_audit'] ?? true)) {
            return;
        }

        $this->skipService->applyExcludeScope($base);
    }

    /**
     * Push available / dismissed / planned into SQL so matched counts match Fill.
     *
     * @param  Builder<SeoArticle>  $base
     * @param  array<string, mixed>  $filters
     */
    private function applyStateSqlScope(Builder $base, SeoProject $project, array $filters): void
    {
        $projectId = (int) $project->getKey();
        $state = (string) ($filters['state'] ?? self::STATE_AVAILABLE);
        $tasksTable = (new SeoProjectTask)->getTable();
        $decisionsTable = (new SeoContentProjectSuggestionDecision)->getTable();

        if ($state === self::STATE_DISMISSED) {
            $base->whereIn('articles.id', function ($sub) use ($projectId, $decisionsTable): void {
                $sub->select('article_id')
                    ->from($decisionsTable)
                    ->where('project_id', $projectId)
                    ->where('source_type', SeoContentProjectSuggestionDecision::SOURCE_SEO_AUDIT)
                    ->where('decision', SeoContentProjectSuggestionDecision::DECISION_DISMISSED)
                    ->whereNotNull('article_id');
            });

            return;
        }

        if ($state === 'planned') {
            $base->whereIn('articles.id', function ($sub) use ($tasksTable): void {
                $sub->select('article_id')
                    ->from($tasksTable)
                    ->whereNull('archived_at')
                    ->whereNotNull('article_id');
            });

            return;
        }

        // available: exclude project dismissed + any active planned task
        $base->whereNotIn('articles.id', function ($sub) use ($projectId, $decisionsTable): void {
            $sub->select('article_id')
                ->from($decisionsTable)
                ->where('project_id', $projectId)
                ->where('source_type', SeoContentProjectSuggestionDecision::SOURCE_SEO_AUDIT)
                ->where('decision', SeoContentProjectSuggestionDecision::DECISION_DISMISSED)
                ->whereNotNull('article_id');
        });

        $base->whereNotIn('articles.id', function ($sub) use ($tasksTable): void {
            $sub->select('article_id')
                ->from($tasksTable)
                ->whereNull('archived_at')
                ->whereNotNull('article_id');
        });
    }

    private function resolvePostTypeSlug(SeoArticle $article): string
    {
        if ($article->relationLoaded('articleMetas')) {
            $meta = $article->articleMetas->firstWhere('meta_key', 'wp_post_type');
            $slug = strtolower(trim((string) ($meta?->meta_value ?? '')));
            if ($slug !== '') {
                return $slug;
            }
        }

        $type = strtolower(trim((string) ($article->type ?? '')));

        return match ($type) {
            'product' => 'product',
            'category' => 'category',
            'product_category', 'product_cat' => 'product_category',
            'page' => 'page',
            default => 'post',
        };
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
}
