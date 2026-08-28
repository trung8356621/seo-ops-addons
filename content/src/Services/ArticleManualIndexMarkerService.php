<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Manual Index checklist marker — chỉ 2 timestamp gần nhất, không auto-crawl.
 */
final class ArticleManualIndexMarkerService
{
    /**
     * @return array{indexed_at: Carbon, previous_indexed_at: Carbon|null}
     */
    public function rotate(mixed $currentIndexedAt, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now())->copy();

        $previous = null;
        if ($currentIndexedAt !== null && $currentIndexedAt !== '') {
            $previous = $currentIndexedAt instanceof Carbon
                ? $currentIndexedAt->copy()
                : Carbon::parse((string) $currentIndexedAt);
        }

        return [
            'indexed_at' => $now,
            'previous_indexed_at' => $previous,
        ];
    }

    /**
     * Lưu marker từ Archived Project Preview.
     * Không restore/unarchive project, không recreate workspace.
     *
     * @return array{
     *     item_id: int,
     *     article_id: int|null,
     *     indexed_at: string,
     *     previous_indexed_at: string|null,
     *     project_restored: false,
     *     workspace_recreated: false
     * }
     */
    public function markFromArchiveItem(SeoProjectArchive $archive, int $archiveItemId): array
    {
        if ($archiveItemId <= 0) {
            throw new InvalidArgumentException('Invalid archive item id.');
        }

        $archiveId = (int) $archive->getKey();
        $archiveSiteId = (int) ($archive->site_id ?? 0);
        if ($archiveId <= 0 || $archiveSiteId <= 0) {
            throw new InvalidArgumentException('Invalid archive context.');
        }

        $this->assertNoActiveCheckIndexAllRun($archiveSiteId);

        return DB::connection('omi_seo_ai')->transaction(function () use ($archiveId, $archiveSiteId, $archiveItemId): array {
            /** @var SeoProjectArchiveItem|null $item */
            $item = SeoProjectArchiveItem::query()
                ->whereKey($archiveItemId)
                ->where('seo_project_archive_id', $archiveId)
                ->lockForUpdate()
                ->first();

            if (! $item instanceof SeoProjectArchiveItem) {
                throw new RuntimeException('Archive item not found for this archive.');
            }

            $snapshot = is_array($item->article_snapshot) ? $item->article_snapshot : [];
            $articleId = (int) ($item->article_id ?? ($snapshot['article_id'] ?? 0));

            $article = null;
            if ($articleId > 0) {
                /** @var SeoArticle|null $locked */
                $locked = SeoArticle::query()
                    ->whereKey($articleId)
                    ->lockForUpdate()
                    ->first();

                if ($locked instanceof SeoArticle) {
                    if ((int) ($locked->site_id ?? 0) !== $archiveSiteId) {
                        throw new RuntimeException('Article does not belong to this archive site.');
                    }
                    $article = $locked;
                }
            }

            $currentIndexedAt = $article?->seoProfile?->indexed_at ?? ($snapshot['indexed_at'] ?? null);
            $rotated = $this->rotate($currentIndexedAt);
            $indexedAt = $rotated['indexed_at'];
            $previousIndexedAt = $rotated['previous_indexed_at'];

            if ($article instanceof SeoArticle) {
                // SEO-owned indexed state (seo_article_profiles) + articles.* projection.
                if (class_exists(\Omnichannel\Addons\Seo\Services\SeoArticleProfileWriter::class)) {
                    app(\Omnichannel\Addons\Seo\Services\SeoArticleProfileWriter::class)->upsert($article, [
                        'indexed_at' => $indexedAt,
                        'previous_indexed_at' => $previousIndexedAt,
                    ]);
                }

                $article->forceFill([
                    'indexed_at' => $indexedAt,
                    'previous_indexed_at' => $previousIndexedAt,
                ])->save();
            }

            $snapshot['indexed_at'] = $indexedAt->toIso8601String();
            $snapshot['previous_indexed_at'] = $previousIndexedAt?->toIso8601String();
            if ($articleId > 0) {
                $snapshot['article_id'] = $articleId;
            }

            $item->article_snapshot = $snapshot;
            $item->save();

            return [
                'item_id' => (int) $item->getKey(),
                'article_id' => $articleId > 0 ? $articleId : null,
                'indexed_at' => $indexedAt->toIso8601String(),
                'previous_indexed_at' => $previousIndexedAt?->toIso8601String(),
                'project_restored' => false,
                'workspace_recreated' => false,
            ];
        });
    }

    /**
     * Manual "Not indexed" từ Archive Preview — clear marker hiện tại, giữ previous.
     * Không restore/unarchive project; chỉ cập nhật Article index marker.
     *
     * @return array{
     *     item_id: int,
     *     article_id: int|null,
     *     indexed_at: null,
     *     previous_indexed_at: string|null,
     *     project_restored: false,
     *     workspace_recreated: false
     * }
     */
    public function clearFromArchiveItem(SeoProjectArchive $archive, int $archiveItemId): array
    {
        if ($archiveItemId <= 0) {
            throw new InvalidArgumentException('Invalid archive item id.');
        }

        $archiveId = (int) $archive->getKey();
        $archiveSiteId = (int) ($archive->site_id ?? 0);
        if ($archiveId <= 0 || $archiveSiteId <= 0) {
            throw new InvalidArgumentException('Invalid archive context.');
        }

        $this->assertNoActiveCheckIndexAllRun($archiveSiteId);

        return DB::connection('omi_seo_ai')->transaction(function () use ($archiveId, $archiveSiteId, $archiveItemId): array {
            /** @var SeoProjectArchiveItem|null $item */
            $item = SeoProjectArchiveItem::query()
                ->whereKey($archiveItemId)
                ->where('seo_project_archive_id', $archiveId)
                ->lockForUpdate()
                ->first();

            if (! $item instanceof SeoProjectArchiveItem) {
                throw new RuntimeException('Archive item not found for this archive.');
            }

            $snapshot = is_array($item->article_snapshot) ? $item->article_snapshot : [];
            $articleId = (int) ($item->article_id ?? ($snapshot['article_id'] ?? 0));

            $article = null;
            if ($articleId > 0) {
                /** @var SeoArticle|null $locked */
                $locked = SeoArticle::query()
                    ->whereKey($articleId)
                    ->lockForUpdate()
                    ->first();

                if ($locked instanceof SeoArticle) {
                    if ((int) ($locked->site_id ?? 0) !== $archiveSiteId) {
                        throw new RuntimeException('Article does not belong to this archive site.');
                    }
                    $article = $locked;
                }
            }

            $currentIndexedAt = $article?->seoProfile?->indexed_at ?? ($snapshot['indexed_at'] ?? null);
            $previousIndexedAt = null;
            if ($currentIndexedAt !== null && $currentIndexedAt !== '') {
                $previousIndexedAt = $currentIndexedAt instanceof Carbon
                    ? $currentIndexedAt->copy()
                    : Carbon::parse((string) $currentIndexedAt);
            } elseif (($snapshot['previous_indexed_at'] ?? null) !== null && ($snapshot['previous_indexed_at'] ?? '') !== '') {
                $previousIndexedAt = Carbon::parse((string) $snapshot['previous_indexed_at']);
            } elseif ($article?->seoProfile?->previous_indexed_at !== null) {
                $previousIndexedAt = $article->seoProfile->previous_indexed_at instanceof Carbon
                    ? $article->seoProfile->previous_indexed_at->copy()
                    : Carbon::parse((string) $article->seoProfile->previous_indexed_at);
            }

            if ($article instanceof SeoArticle) {
                if (class_exists(\Omnichannel\Addons\Seo\Services\SeoArticleProfileWriter::class)) {
                    app(\Omnichannel\Addons\Seo\Services\SeoArticleProfileWriter::class)->upsert($article, [
                        'indexed_at' => null,
                        'previous_indexed_at' => $previousIndexedAt,
                    ]);
                }

                $article->forceFill([
                    'indexed_at' => null,
                    'previous_indexed_at' => $previousIndexedAt,
                ])->save();
            }

            $snapshot['indexed_at'] = null;
            $snapshot['previous_indexed_at'] = $previousIndexedAt?->toIso8601String();
            if ($articleId > 0) {
                $snapshot['article_id'] = $articleId;
            }

            $item->article_snapshot = $snapshot;
            $item->save();

            return [
                'item_id' => (int) $item->getKey(),
                'article_id' => $articleId > 0 ? $articleId : null,
                'indexed_at' => null,
                'previous_indexed_at' => $previousIndexedAt?->toIso8601String(),
                'project_restored' => false,
                'workspace_recreated' => false,
            ];
        });
    }

    /**
     * Reuse Check Index All run table — block manual index races while batch is queued/running.
     */
    private function assertNoActiveCheckIndexAllRun(int $siteId): void
    {
        if ($siteId <= 0) {
            return;
        }

        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('seo_gsc_url_inspection_runs')) {
                return;
            }

            $active = DB::connection('omi_seo_ai')
                ->table('seo_gsc_url_inspection_runs')
                ->where('site_id', $siteId)
                ->whereIn('status', ['queued', 'running'])
                ->exists();
        } catch (Throwable) {
            return;
        }

        if ($active) {
            throw new RuntimeException('Check Index All đang chạy');
        }
    }
}
