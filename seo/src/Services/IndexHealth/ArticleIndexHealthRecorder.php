<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\IndexHealth;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Enums\ArticleIndexCheckStatus;
use Omnichannel\Addons\Seo\Enums\ArticleIndexHealthStatus;
use Omnichannel\Addons\Seo\Models\SeoArticleIndexCheck;
use Omnichannel\Addons\Seo\Models\SeoArticleIndexHealth;
use Omnichannel\Addons\Seo\Services\SeoArticleProfileWriter;
use RuntimeException;

/**
 * Canonical write path for Index Health checks.
 */
final class ArticleIndexHealthRecorder
{
    public function __construct(
        private readonly ArticleIndexCanonicalUrlResolver $urls = new ArticleIndexCanonicalUrlResolver,
        private readonly ArticleIndexHealthEligibility $eligibility = new ArticleIndexHealthEligibility,
        private readonly ArticleIndexHealthPolicy $policy = new ArticleIndexHealthPolicy,
        private readonly ArticleIndexHealthNotificationPublisher $notifications = new ArticleIndexHealthNotificationPublisher,
    ) {}

    /**
     * @return array{
     *     article_id: int,
     *     check_id: int,
     *     status: string,
     *     effective_health: string,
     *     previous_health: string|null,
     *     transitioned_to_dropped: bool,
     *     recovered_from_dropped: bool,
     *     url: string,
     *     checked_at: string,
     * }
     */
    /**
     * @param  array<string, mixed>|null  $diagnostics  optional GSC/inspection diagnostics (JSON)
     */
    public function record(
        SeoArticle $article,
        ArticleIndexCheckStatus $status,
        string $source = 'manual',
        ?int $checkedBy = null,
        ?Carbon $checkedAt = null,
        ?string $notes = null,
        bool $requirePublished = true,
        ?array $diagnostics = null,
    ): array {
        $articleId = (int) $article->getKey();
        $siteId = (int) ($article->site_id ?? 0);
        if ($articleId <= 0 || $siteId <= 0) {
            throw new InvalidArgumentException('Invalid article/site for index health.');
        }

        if ($requirePublished && ! $this->eligibility->isEligible($article)) {
            throw new RuntimeException('Article is not eligible for Index Health (must be observed WP publish with public URL).');
        }

        $url = $this->urls->resolve($article);
        if ($url === null || $url === '') {
            throw new RuntimeException('Canonical public URL is required for Index Health.');
        }

        $source = strtolower(trim($source));
        if (! in_array($source, ['manual', 'gsc', 'other', 'gsc_url_inspection'], true)) {
            $source = 'manual';
        }

        $checkedAt = ($checkedAt ?? Carbon::now())->copy()->utc();

        return DB::connection('omi_seo_ai')->transaction(function () use (
            $article,
            $articleId,
            $siteId,
            $status,
            $source,
            $checkedBy,
            $checkedAt,
            $notes,
            $url,
            $diagnostics,
        ): array {
            $health = SeoArticleIndexHealth::query()
                ->where('article_id', $articleId)
                ->lockForUpdate()
                ->first();

            $previous = $health instanceof SeoArticleIndexHealth
                ? ArticleIndexHealthStatus::tryFrom((string) $health->current_status)
                : null;

            $effective = $this->policy->deriveEffective($status, $previous);

            $checkAttrs = [
                'site_id' => $siteId,
                'article_id' => $articleId,
                'url' => $url,
                'status' => $status->value,
                'effective_health' => $effective->value,
                'checked_at' => $checkedAt,
                'checked_by' => $checkedBy !== null && $checkedBy > 0 ? $checkedBy : null,
                'source' => $source,
                'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            ];

            if ($diagnostics !== null
                && $diagnostics !== []
                && Schema::connection('omi_seo_ai')->hasColumn('seo_article_index_checks', 'diagnostics')
            ) {
                $checkAttrs['diagnostics'] = $diagnostics;
            }

            $check = SeoArticleIndexCheck::query()->create($checkAttrs);

            $attrs = [
                'site_id' => $siteId,
                'canonical_url' => $url,
                'previous_status' => $previous?->value,
                'current_status' => $effective->value,
                'last_checked_at' => $checkedAt,
            ];

            if ($effective === ArticleIndexHealthStatus::Indexed) {
                $attrs['last_indexed_at'] = $checkedAt;
            }
            if (in_array($effective, [
                ArticleIndexHealthStatus::NotIndexed,
                ArticleIndexHealthStatus::Dropped,
            ], true)) {
                $attrs['last_not_indexed_at'] = $checkedAt;
            }

            SeoArticleIndexHealth::query()->updateOrCreate(
                ['article_id' => $articleId],
                $attrs,
            );

            // Compatibility projection for archive checklist / legacy indexed_at.
            if ($effective === ArticleIndexHealthStatus::Indexed
                && Schema::connection('omi_seo_ai')->hasTable('seo_article_profiles')
            ) {
                $previousIndexed = $article->seoProfile?->indexed_at ?? $article->indexed_at ?? null;
                app(SeoArticleProfileWriter::class)->upsert($article, [
                    'indexed_at' => $checkedAt,
                    'previous_indexed_at' => $previousIndexed,
                ]);
                if (Schema::connection('omi_seo_ai')->hasColumn('articles', 'indexed_at')) {
                    $article->forceFill([
                        'indexed_at' => $checkedAt,
                        'previous_indexed_at' => $previousIndexed,
                    ])->save();
                }
            }

            $transitionedToDropped = $effective === ArticleIndexHealthStatus::Dropped
                && $previous !== ArticleIndexHealthStatus::Dropped;
            $recovered = $effective === ArticleIndexHealthStatus::Indexed
                && $previous === ArticleIndexHealthStatus::Dropped;

            if ($transitionedToDropped) {
                $this->notifications->notifyDropped($article, $url, $checkedBy);
            }
            if ($recovered) {
                $this->notifications->resolveDropped($article);
            }

            return [
                'article_id' => $articleId,
                'check_id' => (int) $check->getKey(),
                'status' => $status->value,
                'effective_health' => $effective->value,
                'previous_health' => $previous?->value,
                'transitioned_to_dropped' => $transitionedToDropped,
                'recovered_from_dropped' => $recovered,
                'url' => $url,
                'checked_at' => $checkedAt->toIso8601String(),
            ];
        });
    }

    /**
     * @param  list<int>  $articleIds
     * @return array{recorded: int, results: list<array<string, mixed>>, errors: list<array{article_id: int, message: string}>}
     */
    public function recordBulk(
        array $articleIds,
        ArticleIndexCheckStatus $status,
        string $source = 'manual',
        ?int $checkedBy = null,
    ): array {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $articleIds),
            static fn (int $id): bool => $id > 0,
        )));

        $recorded = 0;
        $results = [];
        $errors = [];

        foreach ($ids as $id) {
            $article = SeoArticle::query()->find($id);
            if (! $article instanceof SeoArticle) {
                $errors[] = ['article_id' => $id, 'message' => 'Article not found.'];
                continue;
            }

            try {
                $results[] = $this->record($article, $status, $source, $checkedBy);
                $recorded++;
            } catch (\Throwable $e) {
                $errors[] = ['article_id' => $id, 'message' => $e->getMessage()];
            }
        }

        return [
            'recorded' => $recorded,
            'results' => $results,
            'errors' => $errors,
        ];
    }
}
