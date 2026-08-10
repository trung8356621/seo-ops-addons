<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Media\Models\SeoMedia;
use App\Models\Site;
use Illuminate\Support\Carbon;

class SeoMediaLibraryService
{
    /**
     * @return array{
     *     images: list<array<string, mixed>>,
     *     total: int,
     *     total_pages: int,
     *     page: int,
     *     error: string|null,
     * }
     */
    public function fetch(
        Site $site,
        ?string $month,
        int $page = 1,
        ?string $search = null,
        int $perPage = 50,
        ?int $articleId = null,
        ?array $restrictToArticleIds = null,
    ): array {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $rows = $this->queryMedia($site, $month, $search, $articleId, $restrictToArticleIds)->get();

        $merged = $rows
            ->map(fn (SeoMedia $media): array => $this->mapMediaItem($media))
            ->sortByDesc('sort_at')
            ->values();

        $total = $merged->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $images = $merged->slice($offset, $perPage)->values()->all();

        return [
            'images' => $images,
            'total' => $total,
            'total_pages' => $totalPages,
            'page' => $page,
            'error' => null,
        ];
    }

    public function renameLocalBySlug(SeoMedia $media, string $newSlug): void
    {
        app(SeoMediaStorageService::class)->renameBySlug($media, $newSlug);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<SeoMedia>
     */
    /**
     * Gắn ảnh test prompt (chưa có site) với bài viết đang mở — tối đa vài bản ghi mới nhất.
     */
    public function assignRecentOrphanMediaToArticle(Site $site, int $articleId): int
    {
        if ($articleId <= 0) {
            return 0;
        }

        $ids = SeoMedia::query()
            ->whereNull('site_id')
            ->whereNull('article_id')
            ->whereIn('source', ['ai_prompt', 'ai_video_prompt'])
            ->where('created_at', '>=', now()->subHours(24))
            ->where('path', 'not like', '%placeholder-loading%')
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', 'completed');
            })
            ->orderByDesc('id')
            ->limit(5)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return SeoMedia::query()
            ->whereIn('id', $ids)
            ->update([
                'site_id' => $site->id,
                'article_id' => $articleId,
            ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<SeoMedia>
     */
    private function queryMedia(
        Site $site,
        ?string $month,
        ?string $search,
        ?int $articleId = null,
        ?array $restrictToArticleIds = null,
    ) {
        $query = SeoMedia::query();

        if ($articleId !== null && $articleId > 0) {
            $wpAttachmentIds = app(MediaLibraryArticleResolver::class)
                ->wordpressAttachmentIdsForArticles((int) $site->id, [$articleId]);

            $query->where(function ($q) use ($articleId, $wpAttachmentIds): void {
                $q->where('article_id', $articleId);

                if ($wpAttachmentIds !== []) {
                    $q->orWhere(function ($sub) use ($wpAttachmentIds): void {
                        $sub->whereIn('wp_attachment_id', $wpAttachmentIds);
                    });
                }
            });
        } else {
            $query->where('site_id', $site->id);

            if ($restrictToArticleIds !== null) {
                if ($restrictToArticleIds === []) {
                    return $query->whereRaw('0 = 1');
                }

                $wpAttachmentIds = app(MediaLibraryArticleResolver::class)
                    ->wordpressAttachmentIdsForArticles((int) $site->id, $restrictToArticleIds);

                $query->where(function ($q) use ($restrictToArticleIds, $wpAttachmentIds): void {
                    $q->whereIn('article_id', $restrictToArticleIds);

                    if ($wpAttachmentIds !== []) {
                        $q->orWhere(function ($sub) use ($wpAttachmentIds): void {
                            $sub->whereIn('wp_attachment_id', $wpAttachmentIds);
                        });
                    }
                });
            }
        }

        $query->orderByDesc('id');

        $query->where(function ($statusQuery): void {
            $statusQuery->whereNull('status')
                ->orWhere('status', 'completed');
        });

        $this->applyMonthFilter($query, 'created_at', $month);

        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($q) use ($like): void {
                $q->where('slug', 'like', $like)
                    ->orWhere('filename', 'like', $like)
                    ->orWhere('alt_text', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyMonthFilter($query, string $column, ?string $month): void
    {
        if (! filled($month)) {
            return;
        }

        try {
            $start = Carbon::createFromFormat('Y-m', (string) $month)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $query->whereBetween($column, [$start, $end]);
        } catch (\Throwable) {
            // ignore invalid month
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapMediaItem(SeoMedia $media): array
    {
        $createdAt = $media->created_at;
        $source = (string) $media->source;
        $mediaType = $this->resolveMediaType($media);
        $kind = str_starts_with($source, 'ai_') ? 'generated' : 'local';
        $alt = filled($media->alt_text) ? (string) $media->alt_text : (string) $media->slug;

        $publicUrl = $media->publicUrl();

        return [
            'kind' => $kind,
            'id' => (int) $media->id,
            'seo_media_id' => (int) $media->id,
            'article_id' => $media->firstArticleId(),
            'wp_attachment_id' => $media->wp_attachment_id !== null ? (int) $media->wp_attachment_id : null,
            'slug' => (string) $media->slug,
            'url' => $publicUrl,
            'thumb_url' => $publicUrl,
            'media_type' => $mediaType,
            'title' => '',
            'alt' => $alt,
            'source' => $source,
            'ai_generator' => filled($media->ai_generator) ? (string) $media->ai_generator : null,
            'created_at' => $createdAt?->toIso8601String(),
            'sort_at' => $createdAt?->timestamp ?? 0,
        ];
    }

    private function resolveMediaType(SeoMedia $media): string
    {
        $source = strtolower(trim((string) $media->source));
        if ($source === 'ai_video_prompt') {
            return 'video';
        }

        $filename = strtolower(trim((string) $media->filename));
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        return in_array($ext, ['mp4', 'mov', 'm4v', 'webm', 'ogv', 'avi', 'mpeg', 'mpg'], true)
            ? 'video'
            : 'image';
    }
}
