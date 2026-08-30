<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\IndexHealth;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Omnichannel\Addons\Seo\Enums\ArticleIndexHealthStatus;
use Omnichannel\Addons\Seo\Models\SeoArticleIndexCheck;
use Omnichannel\Addons\Seo\Models\SeoArticleIndexHealth;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Read model for Index Health list/summary/history.
 */
final class ArticleIndexHealthQueryService
{
    public function __construct(
        private readonly ArticleIndexHealthEligibility $eligibility = new ArticleIndexHealthEligibility,
        private readonly ArticleIndexCanonicalUrlResolver $urls = new ArticleIndexCanonicalUrlResolver,
        private readonly ArticleIndexHealthPolicy $policy = new ArticleIndexHealthPolicy,
        private readonly ArticleIndexCheckUrlBuilder $checkUrls = new ArticleIndexCheckUrlBuilder,
    ) {}

    /**
     * @param  array{
     *     site_id?: int|null,
     *     search?: string|null,
     *     tab?: string|null,
     *     post_type?: string|null,
     *     per_page?: int,
     * }  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = max(10, min(100, (int) ($filters['per_page'] ?? 25)));
        $query = $this->baseEligibleQuery($filters);
        $this->applyTabFilter($query, (string) ($filters['tab'] ?? 'needs_review'));

        /** @var LengthAwarePaginator<int, SeoArticle> $page */
        $page = $query
            ->orderByDesc('articles.id')
            ->paginate($perPage);

        $page->setCollection(
            $page->getCollection()->map(fn (SeoArticle $article): array => $this->presentRow($article))
        );

        return $page;
    }

    /**
     * @param  array{site_id?: int|null}  $filters
     * @return array{
     *     due: int,
     *     indexed: int,
     *     not_indexed: int,
     *     dropped: int,
     *     never_checked: int,
     *     needs_review: int,
     * }
     */
    public function summary(array $filters = []): array
    {
        if (! $this->tablesReady()) {
            return [
                'due' => 0,
                'indexed' => 0,
                'not_indexed' => 0,
                'dropped' => 0,
                'never_checked' => 0,
                'needs_review' => 0,
            ];
        }

        $now = Carbon::now();
        $dueBefore = $now->copy()->subMonthsNoOverflow(ArticleIndexHealthPolicy::RECHECK_MONTHS);

        $base = $this->baseEligibleQuery($filters);
        $eligibleIds = (clone $base)->select('articles.id')->pluck('articles.id')->map(static fn ($id): int => (int) $id)->all();

        if ($eligibleIds === []) {
            return [
                'due' => 0,
                'indexed' => 0,
                'not_indexed' => 0,
                'dropped' => 0,
                'never_checked' => 0,
                'needs_review' => 0,
            ];
        }

        $healthRows = SeoArticleIndexHealth::query()
            ->whereIn('article_id', $eligibleIds)
            ->get()
            ->keyBy('article_id');

        $indexed = 0;
        $notIndexed = 0;
        $dropped = 0;
        $neverChecked = 0;
        $due = 0;
        $needsReview = 0;

        foreach ($eligibleIds as $id) {
            /** @var SeoArticleIndexHealth|null $health */
            $health = $healthRows->get($id);
            if (! $health instanceof SeoArticleIndexHealth) {
                $neverChecked++;
                $due++;
                $needsReview++;
                continue;
            }

            $status = ArticleIndexHealthStatus::tryFrom((string) $health->current_status)
                ?? ArticleIndexHealthStatus::Unknown;
            $lastChecked = $health->last_checked_at;
            $isDue = $this->policy->isDue($lastChecked, $now);

            match ($status) {
                ArticleIndexHealthStatus::Indexed => $indexed++,
                ArticleIndexHealthStatus::NotIndexed => $notIndexed++,
                ArticleIndexHealthStatus::Dropped => $dropped++,
                ArticleIndexHealthStatus::Unknown => null,
            };

            if ($lastChecked === null) {
                $neverChecked++;
            }
            if ($isDue) {
                $due++;
            }
            if ($this->policy->needsReview($status, $lastChecked, $now)) {
                $needsReview++;
            }
        }

        return [
            'due' => $due,
            'indexed' => $indexed,
            'not_indexed' => $notIndexed,
            'dropped' => $dropped,
            'never_checked' => $neverChecked,
            'needs_review' => $needsReview,
        ];
    }

    /**
     * @return list<array{checked_at: string, status: string, effective_health: string, source: string}>
     */
    public function history(int $articleId, int $limit = 20): array
    {
        if ($articleId <= 0 || ! $this->tablesReady()) {
            return [];
        }

        return SeoArticleIndexCheck::query()
            ->where('article_id', $articleId)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(static function (SeoArticleIndexCheck $row): array {
                $diagnostics = is_array($row->diagnostics) ? $row->diagnostics : null;
                $source = (string) $row->source;
                $sourceLabel = match ($source) {
                    'gsc_url_inspection' => 'GSC URL Inspection',
                    'manual' => 'Manual',
                    'gsc' => 'GSC',
                    default => $source !== '' ? $source : 'Manual',
                };

                return [
                    'checked_at' => $row->checked_at?->toIso8601String() ?? '',
                    'checked_at_label' => $row->checked_at !== null
                        ? SystemDateTime::formatDate($row->checked_at)
                        : '',
                    'status' => (string) $row->status,
                    'effective_health' => (string) $row->effective_health,
                    'source' => $source,
                    'source_label' => $sourceLabel,
                    'url' => (string) $row->url,
                    'diagnostics' => $diagnostics,
                    'canonical_mismatch' => (bool) ($diagnostics['canonical_mismatch'] ?? false),
                    'last_crawl_time' => isset($diagnostics['last_crawl_time'])
                        ? (string) $diagnostics['last_crawl_time']
                        : null,
                    'verdict' => isset($diagnostics['verdict']) ? (string) $diagnostics['verdict'] : null,
                    'coverage_state' => isset($diagnostics['coverage_state'])
                        ? (string) $diagnostics['coverage_state']
                        : null,
                    'google_canonical' => isset($diagnostics['google_canonical'])
                        ? (string) $diagnostics['google_canonical']
                        : null,
                    'user_canonical' => isset($diagnostics['user_canonical'])
                        ? (string) $diagnostics['user_canonical']
                        : null,
                ];
            })
            ->all();
    }

    public function countDue(?int $siteId = null): int
    {
        return (int) ($this->summary(['site_id' => $siteId])['due'] ?? 0);
    }

    /**
     * @param  array{site_id?: int|null, search?: string|null, post_type?: string|null}  $filters
     * @return Builder<SeoArticle>
     */
    private function baseEligibleQuery(array $filters): Builder
    {
        $query = SeoArticle::query()
            ->select('articles.*')
            ->with(['wordpressLink', 'site:id,domain', 'indexHealth', 'articleMetas']);

        SeoAccessControl::applyAccessibleSiteScope($query, 'articles.site_id');

        $this->eligibility->scopeEligible($query);

        $siteId = (int) ($filters['site_id'] ?? 0);
        if ($siteId > 0) {
            $query->where('articles.site_id', $siteId);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('articles.title', 'like', $like)
                    ->orWhere('articles.slug', 'like', $like);
            });
        }

        $postType = strtolower(trim((string) ($filters['post_type'] ?? '')));
        if ($postType !== '') {
            // `article` is the retired label for a plain post.
            $contentType = ContentType::tryFromString($postType === 'article' ? 'post' : $postType);

            if ($contentType !== null) {
                ArticleContentClassification::scopeContentType($query, $contentType);
            } else {
                $query->whereHas('articleMetas', static function (Builder $meta) use ($postType): void {
                    $meta->where('meta_key', ArticleContentClassification::META_WP_POST_TYPE)
                        ->where('meta_value', $postType);
                });
            }
        }

        return $query;
    }

    /**
     * @param  Builder<SeoArticle>  $query
     */
    private function applyTabFilter(Builder $query, string $tab): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        $now = Carbon::now();
        $dueBefore = $now->copy()->subMonthsNoOverflow(ArticleIndexHealthPolicy::RECHECK_MONTHS);

        match ($tab) {
            'dropped' => $query->whereHas('indexHealth', static fn (Builder $h) => $h->where('current_status', 'dropped')),
            'not_indexed' => $query->whereHas('indexHealth', static fn (Builder $h) => $h->where('current_status', 'not_indexed')),
            'indexed' => $query->whereHas('indexHealth', static fn (Builder $h) => $h->where('current_status', 'indexed')),
            'all' => null,
            default => $query->where(function (Builder $q) use ($dueBefore): void {
                $q->whereDoesntHave('indexHealth')
                    ->orWhereHas('indexHealth', static function (Builder $h) use ($dueBefore): void {
                        $h->whereIn('current_status', ['dropped', 'not_indexed', 'unknown'])
                            ->orWhereNull('last_checked_at')
                            ->orWhere('last_checked_at', '<=', $dueBefore);
                    });
            }),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRow(SeoArticle $article): array
    {
        $url = $this->urls->resolve($article);
        /** @var SeoArticleIndexHealth|null $health */
        $health = $article->relationLoaded('indexHealth')
            ? $article->getRelation('indexHealth')
            : $article->indexHealth;

        $status = $health instanceof SeoArticleIndexHealth
            ? (ArticleIndexHealthStatus::tryFrom((string) $health->current_status) ?? ArticleIndexHealthStatus::Unknown)
            : null;
        $lastChecked = $health?->last_checked_at;
        $now = Carbon::now();
        $isDue = $this->policy->isDue($lastChecked, $now);
        $needsReview = $this->policy->needsReview($status, $lastChecked, $now);

        $healthLabel = match (true) {
            $status === null => 'Never checked',
            $status === ArticleIndexHealthStatus::Indexed => 'Indexed',
            $status === ArticleIndexHealthStatus::NotIndexed => 'Not indexed',
            $status === ArticleIndexHealthStatus::Dropped => 'Dropped',
            default => 'Unknown',
        };

        $latestCheck = null;
        if ($this->tablesReady()) {
            $latestCheck = SeoArticleIndexCheck::query()
                ->where('article_id', (int) $article->getKey())
                ->orderByDesc('checked_at')
                ->orderByDesc('id')
                ->first();
        }

        $source = $latestCheck instanceof SeoArticleIndexCheck ? (string) $latestCheck->source : null;
        $sourceLabel = match ($source) {
            'gsc_url_inspection' => 'GSC URL Inspection',
            'manual' => 'Manual',
            'gsc' => 'GSC',
            null, '' => null,
            default => $source,
        };
        $diagnostics = $latestCheck instanceof SeoArticleIndexCheck && is_array($latestCheck->diagnostics)
            ? $latestCheck->diagnostics
            : null;
        $lastCrawl = isset($diagnostics['last_crawl_time']) ? (string) $diagnostics['last_crawl_time'] : null;
        $lastCrawlLabel = null;
        if ($lastCrawl !== null && $lastCrawl !== '') {
            try {
                $lastCrawlLabel = SystemDateTime::formatDate(Carbon::parse($lastCrawl)) ?? $lastCrawl;
            } catch (\Throwable) {
                $lastCrawlLabel = $lastCrawl;
            }
        }

        return [
            'article_id' => (int) $article->getKey(),
            'title' => (string) ($article->title ?? ''),
            'post_type' => $this->postTypeLabel($article),
            'domain' => (string) ($article->site?->domain ?? ''),
            'site_id' => (int) ($article->site_id ?? 0),
            'canonical_url' => $url,
            'check_url' => $this->checkUrls->forCanonicalUrl($url),
            'health' => $status?->value,
            'health_label' => $healthLabel,
            'needs_attention' => $status?->needsAttention() ?? true,
            'is_due' => $isDue,
            'needs_review' => $needsReview,
            'last_checked_at' => $lastChecked?->toIso8601String(),
            'last_checked_label' => $lastChecked !== null
                ? (SystemDateTime::formatDate($lastChecked) ?? '—')
                : 'Never checked',
            'last_check_source' => $source,
            'last_check_source_label' => $sourceLabel,
            'google_crawl_label' => $lastCrawlLabel,
            'canonical_mismatch' => (bool) ($diagnostics['canonical_mismatch'] ?? false),
            'next_check_due_at' => $this->policy->nextCheckDueAt($lastChecked)?->toIso8601String(),
            'next_check_label' => $lastChecked !== null
                ? (SystemDateTime::formatDate($this->policy->nextCheckDueAt($lastChecked)) ?? '—')
                : 'Check now',
            'published_at' => $article->wordpressLink?->observed_modified_at?->toIso8601String()
                ?? $article->wordpressLink?->external_modified_at?->toIso8601String(),
            'published_label' => ($article->wordpressLink?->observed_modified_at
                ?? $article->wordpressLink?->external_modified_at) !== null
                ? (SystemDateTime::formatDate(
                    $article->wordpressLink?->observed_modified_at
                    ?? $article->wordpressLink?->external_modified_at
                ) ?? '—')
                : '—',
            'skip_seo_audit' => (bool) ($article->skip_seo_audit ?? false),
        ];
    }

    private function postTypeLabel(SeoArticle $article): string
    {
        $classification = ArticleContentClassification::for($article);

        if ($classification->isTerm()) {
            return $classification->equals(ContentType::Product) ? 'product_category' : 'category';
        }

        return $classification->contentType()->value;
    }

    private function tablesReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_article_index_health')
            && Schema::connection('omi_seo_ai')->hasTable('seo_article_index_checks');
    }
}
