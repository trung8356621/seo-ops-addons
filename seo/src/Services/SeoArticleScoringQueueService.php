<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;


use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Omnichannel\Addons\Content\Jobs\AnalyzeArticleSeoJob;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleSeoInventoryPolicy;
use Omnichannel\Addons\Content\Support\WpBackedComparableInventory;
use Omnichannel\Addons\Seo\Support\SeoScoringStatus;
use App\Support\RuntimeLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class SeoArticleScoringQueueService
{
    public function __construct(
        private readonly SeoAnalyzerService $analyzer,
    ) {}

    public function dispatchForArticle(SeoArticle $article, bool $force = false): bool
    {
        if (! $article->isEligibleForWorkspaceSeoScoring()) {
            return false;
        }

        if (! $force) {
            $status = SeoScoringStatus::readStatus($article);
            if (in_array($status, [SeoScoringStatus::STATUS_PENDING, SeoScoringStatus::STATUS_PROCESSING], true)) {
                return false;
            }

            if (SeoScoringStatus::hasBeenAnalyzed($article)) {
                return false;
            }
        }

        SeoScoringStatus::writeStatus($article, SeoScoringStatus::STATUS_PENDING);
        AnalyzeArticleSeoJob::dispatch((int) $article->id);

        return true;
    }

    /**
     * @param  array<string, mixed>  $syncItem
     */
    public function dispatchIfSyncItemChanged(
        SeoArticle $article,
        array $syncItem,
        ?SeoArticle $existingBeforeSave = null,
    ): bool {
        if (! $article->isEligibleForWorkspaceSeoScoring()) {
            return false;
        }

        $fingerprint = $this->buildSyncItemFingerprint($syncItem);
        $previousFingerprint = $existingBeforeSave instanceof SeoArticle
            ? SeoScoringStatus::readFingerprint($existingBeforeSave)
            : SeoScoringStatus::readFingerprint($article);

        if ($previousFingerprint === $fingerprint && SeoScoringStatus::hasBeenAnalyzed($article)) {
            return false;
        }

        return $this->dispatchForArticle($article, force: true);
    }

    /**
     * Workspace scoring progress (includes local-only SEO inventory).
     *
     * For Domain Overview / Site Sync website presentation use
     * {@see domainWpBackedProgress()} instead — same counters, WP-backed membership.
     *
     * @return array{
     *   total: int,
     *   completed: int,
     *   pending: int,
     *   processing: int,
     *   failed: int,
     *   remaining: int
     * }
     */
    public function domainProgress(int $siteId): array
    {
        return $this->progressForQuery($this->eligibleArticlesQuery($siteId));
    }

    /**
     * Website/WP-backed scoring progress for Domain Overview + Site Sync status UI.
     *
     * Membership = Workspace eligibility ∩ WP-backed comparable inventory
     * ({@see WpBackedComparableInventory}). Local-only rows stay eligible for the
     * scoring engine via {@see domainProgress()} / queue methods — not this view.
     *
     * @return array{
     *   total: int,
     *   completed: int,
     *   pending: int,
     *   processing: int,
     *   failed: int,
     *   remaining: int
     * }
     */
    public function domainWpBackedProgress(int $siteId, ?string $language = null): array
    {
        return $this->progressForQuery($this->wpBackedEligibleArticlesQuery($siteId, $language));
    }

    /**
     * @param  Builder<SeoArticle>  $base
     * @return array{
     *   total: int,
     *   completed: int,
     *   pending: int,
     *   processing: int,
     *   failed: int,
     *   remaining: int
     * }
     */
    private function progressForQuery(Builder $base): array
    {
        $total = (clone $base)->count();

        $completed = (clone $base)->where(function (Builder $query): void {
            $this->applyCompletedScope($query);
        })->count();

        $pending = (clone $base)->whereHas('articleMetas', static function (Builder $meta): void {
            $meta->where('meta_key', SeoScoringStatus::META_KEY_STATUS)
                ->where('meta_value', SeoScoringStatus::STATUS_PENDING);
        })->count();

        $processing = (clone $base)->whereHas('articleMetas', static function (Builder $meta): void {
            $meta->where('meta_key', SeoScoringStatus::META_KEY_STATUS)
                ->where('meta_value', SeoScoringStatus::STATUS_PROCESSING);
        })->count();

        $failed = (clone $base)->whereHas('articleMetas', static function (Builder $meta): void {
            $meta->where('meta_key', SeoScoringStatus::META_KEY_STATUS)
                ->where('meta_value', SeoScoringStatus::STATUS_FAILED);
        })->count();

        $remaining = max(0, $total - $completed - $pending - $processing);

        return [
            'total' => $total,
            'completed' => $completed,
            'pending' => $pending,
            'processing' => $processing,
            'failed' => $failed,
            'remaining' => $remaining,
        ];
    }

    /**
     * Queue missing Workspace scores + stale fingerprint articles.
     * Does not overwrite provider scores — AnalyzeArticleSeoJob writes Workspace only.
     *
     * @param  array{run_id?: int|null, operation_id?: string|null, step_id?: int|null}  $context
     * @return array{queued: int, skipped: int, stale_queued: int, missing_queued: int}
     */
    public function queueMissingOrStaleForSite(int $siteId, array $context = []): array
    {
        return $this->queueMissingOrStaleForQuery(
            $this->eligibleArticlesQuery($siteId),
            $this->missingArticlesQuery($siteId),
            $siteId,
            $context,
            'seo.scoring.queue_missing_or_stale',
        );
    }

    /**
     * Site Sync website lifecycle: queue missing/stale for WP-backed comparable only.
     * Does not enqueue local-only Workspace inventory.
     *
     * @param  array{run_id?: int|null, operation_id?: string|null, step_id?: int|null, language?: string|null}  $context
     * @return array{queued: int, skipped: int, stale_queued: int, missing_queued: int}
     */
    public function queueMissingOrStaleWpBackedForSite(int $siteId, array $context = []): array
    {
        $language = isset($context['language']) ? trim((string) $context['language']) : null;
        if ($language === '') {
            $language = null;
        }
        $base = $this->wpBackedEligibleArticlesQuery($siteId, $language);

        return $this->queueMissingOrStaleForQuery(
            $base,
            $this->missingArticlesQueryFromBase(clone $base),
            $siteId,
            $context,
            'seo.scoring.queue_missing_or_stale_wp_backed',
        );
    }

    /**
     * @param  Builder<SeoArticle>  $eligibleBase
     * @param  Builder<SeoArticle>  $missingQuery
     * @param  array{run_id?: int|null, operation_id?: string|null, step_id?: int|null}  $context
     * @return array{queued: int, skipped: int, stale_queued: int, missing_queued: int}
     */
    private function queueMissingOrStaleForQuery(
        Builder $eligibleBase,
        Builder $missingQuery,
        int $siteId,
        array $context,
        string $logEvent,
    ): array {
        $missing = $this->queueArticles($missingQuery);
        $staleQueued = 0;

        (clone $eligibleBase)
            ->with('articleMetas')
            ->orderBy('articles.id')
            ->chunkById(100, function ($articles) use (&$staleQueued): void {
                foreach ($articles as $article) {
                    if (! $article instanceof SeoArticle) {
                        continue;
                    }
                    $status = SeoScoringStatus::readStatus($article);
                    if (in_array($status, [SeoScoringStatus::STATUS_PENDING, SeoScoringStatus::STATUS_PROCESSING], true)) {
                        continue;
                    }
                    if (! SeoScoringStatus::hasBeenAnalyzed($article)) {
                        continue;
                    }
                    $stored = SeoScoringStatus::readFingerprint($article);
                    $current = $this->buildArticleFingerprint($article);
                    if ($stored !== null && $stored === $current) {
                        continue;
                    }
                    if ($this->dispatchForArticle($article, force: true)) {
                        $staleQueued++;
                    }
                }
            }, 'articles.id', 'id');

        if ($context !== []) {
            RuntimeLogger::warning($logEvent, array_merge($context, [
                'site_id' => $siteId,
                'missing_queued' => $missing['queued'],
                'stale_queued' => $staleQueued,
            ]));
        }

        return [
            'queued' => $missing['queued'] + $staleQueued,
            'skipped' => $missing['skipped'],
            'stale_queued' => $staleQueued,
            'missing_queued' => $missing['queued'],
        ];
    }

    /**
     * @return array{queued: int, skipped: int}
     */
    public function queueMissingForSite(int $siteId): array
    {
        return $this->queueArticles($this->missingArticlesQuery($siteId));
    }

    /**
     * @return array{queued: int, skipped: int}
     */
    public function queueFailedForSite(int $siteId): array
    {
        $query = $this->eligibleArticlesQuery($siteId)->whereHas('articleMetas', static function (Builder $meta): void {
            $meta->where('meta_key', SeoScoringStatus::META_KEY_STATUS)
                ->where('meta_value', SeoScoringStatus::STATUS_FAILED);
        });

        return $this->queueArticles($query, force: true);
    }

    /**
     * @return array{queued: int, skipped: int}
     */
    public function queueAllForSite(int $siteId): array
    {
        return $this->queueArticles($this->eligibleArticlesQuery($siteId), force: true);
    }

    public function markProcessing(SeoArticle $article): void
    {
        SeoScoringStatus::writeStatus($article, SeoScoringStatus::STATUS_PROCESSING);
    }

    public function markCompleted(SeoArticle $article): void
    {
        SeoScoringStatus::writeStatus($article, SeoScoringStatus::STATUS_COMPLETED);
        SeoScoringStatus::writeFingerprint($article, $this->buildArticleFingerprint($article));
    }

    public function markFailed(SeoArticle $article, string $message): void
    {
        SeoScoringStatus::writeStatus($article, SeoScoringStatus::STATUS_FAILED);

        Log::warning('SEO article scoring failed', [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0),
            'error' => $message,
        ]);
    }

    /**
     * @param  Builder<SeoArticle>  $query
     * @return array{queued: int, skipped: int}
     */
    private function queueArticles(Builder $query, bool $force = false): array
    {
        $queued = 0;
        $skipped = 0;

        $query->select(['articles.id'])->orderBy('articles.id')->chunkById(200, function ($articles) use (&$queued, &$skipped, $force): void {
            foreach ($articles as $article) {
                if (! $article instanceof SeoArticle) {
                    continue;
                }

                if ($this->dispatchForArticle($article, $force)) {
                    $queued++;
                } else {
                    $skipped++;
                }
            }
        }, 'articles.id', 'id');

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * @return Builder<SeoArticle>
     */
    private function eligibleArticlesQuery(int $siteId): Builder
    {
        $query = SeoArticle::query()
            ->where('articles.site_id', $siteId)
            ->countsTowardSeoScore()
            ->where('articles.status', '!=', 'trash');

        // Same SEO-content universe as Data Health / Focus Keyword coverage.
        return ArticleSeoInventoryPolicy::scopeCandidates($query);
    }

    /**
     * Website presentation denominator: Workspace eligibility ∩ WP-backed comparable.
     *
     * @return Builder<SeoArticle>
     */
    private function wpBackedEligibleArticlesQuery(int $siteId, ?string $language = null): Builder
    {
        $query = WpBackedComparableInventory::scopeArticles(
            $this->eligibleArticlesQuery($siteId),
        );
        $language = $language !== null ? trim($language) : '';
        if ($language !== '') {
            $query->where('articles.language', $language);
        }

        return $query;
    }

    /**
     * @return Builder<SeoArticle>
     */
    private function missingArticlesQuery(int $siteId): Builder
    {
        return $this->missingArticlesQueryFromBase($this->eligibleArticlesQuery($siteId));
    }

    /**
     * @param  Builder<SeoArticle>  $query
     * @return Builder<SeoArticle>
     */
    private function missingArticlesQueryFromBase(Builder $query): Builder
    {
        $query->where(function (Builder $sub): void {
            $sub->whereDoesntHave('articleMetas', static function (Builder $meta): void {
                $meta->where('meta_key', \Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry::META_KEY_VIOLATIONS);
            })
                ->orWhereNull($this->seoScoreColumn())
                ->orWhereHas('articleMetas', static function (Builder $meta): void {
                    $meta->where('meta_key', SeoScoringStatus::META_KEY_STATUS)
                        ->where('meta_value', SeoScoringStatus::STATUS_FAILED);
                });
        });

        return $query;
    }

    /**
     * Qualify score column for queries that may leftJoin seo_article_profiles (sap_skip).
     */
    private function seoScoreColumn(): string
    {
        try {
            if (Schema::connection('omi_seo_ai')->hasColumn('articles', 'seo_score')) {
                return 'articles.seo_score';
            }
        } catch (\Throwable) {
            // Fall through to profile column.
        }

        return 'sap_skip.seo_score';
    }

    /**
     * @param  Builder<SeoArticle>  $query
     */
    private function applyCompletedScope(Builder $query): void
    {
        $query->whereHas('articleMetas', static function (Builder $meta): void {
            $meta->where('meta_key', \Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry::META_KEY_VIOLATIONS);
        })->whereNotNull($this->seoScoreColumn());
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function buildSyncItemFingerprint(array $item): string
    {
        $scoring = is_array($item['scoring'] ?? null) ? $item['scoring'] : [];
        $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];

        $parts = [
            trim((string) ($item['title'] ?? '')),
            trim((string) ($scoring['body'] ?? $item['content'] ?? '')),
            trim((string) ($scoring['slug'] ?? $item['slug'] ?? '')),
            trim((string) ($scoring['seo_title'] ?? $seo['seo_title'] ?? '')),
            trim((string) ($scoring['meta_description'] ?? $seo['meta_description'] ?? '')),
            trim((string) ($scoring['focus_keyword'] ?? $seo['focus_keyword'] ?? '')),
        ];

        return hash('sha256', implode("\n", $parts));
    }

    public function buildArticleFingerprint(SeoArticle $article): string
    {
        $article->loadMissing(['articleMetas', 'faqs']);

        $parts = [
            trim((string) ($article->title ?? '')),
            $this->analyzer->resolveScoringContentForArticle($article),
            trim((string) ($article->slug ?? '')),
            $this->analyzer->resolveSeoTitleForArticle($article),
            $this->analyzer->resolveMetaDescriptionForArticle($article),
            trim((string) ($this->analyzer->resolveFocusKeywordForArticle($article) ?? '')),
            json_encode($article->resolveFaqs(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
        ];

        return hash('sha256', implode("\n", $parts));
    }
}
