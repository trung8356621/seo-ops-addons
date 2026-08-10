<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Media\Models\SeoMedia;
use App\Models\Site;
use Illuminate\Support\Carbon;

final class AiImageProcessingService
{
    public function __construct(
        private readonly ArticleEditorMediaAiService $articleEditorMediaAi,
    ) {}

    public function reconcileStaleJobsForSite(int $siteId): void
    {
        if ($siteId <= 0) {
            return;
        }

        $articleIds = SeoMedia::query()
            ->where('site_id', $siteId)
            ->whereIn('source', ['ai_prompt', 'ai_video_prompt'])
            ->where('status', 'processing')
            ->get()
            ->flatMap(static fn (SeoMedia $media): array => SeoMedia::normalizeArticleIds($media->article_id))
            ->unique()
            ->filter(static fn (int $id): bool => $id > 0);

        foreach ($articleIds as $articleId) {
            $this->articleEditorMediaAi->reconcileStaleAiMediaJobs((int) $articleId);
        }
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     total_pages: int,
     *     page: int,
     *     counts: array{all: int, processing: int, completed: int, failed: int},
     * }
     */
    public function fetch(
        Site $site,
        ?string $statusFilter,
        int $page = 1,
        int $perPage = 48,
    ): array {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $baseQuery = SeoMedia::query()
            ->where('site_id', $site->id)
            ->whereIn('source', ['ai_prompt', 'ai_video_prompt']);

        $counts = [
            'all' => (clone $baseQuery)->count(),
            'processing' => (clone $baseQuery)->where('status', 'processing')->count(),
            'failed' => (clone $baseQuery)->where('status', 'failed')->count(),
            'completed' => (clone $baseQuery)->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', 'completed');
            })->count(),
        ];

        $query = clone $baseQuery;
        $this->applyStatusFilter($query, $statusFilter);
        $query->orderByDesc('id');

        $total = (clone $query)->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $items = $query
            ->offset($offset)
            ->limit($perPage)
            ->get()
            ->map(fn (SeoMedia $media): array => $this->mapItem($media))
            ->values()
            ->all();

        return [
            'items' => $items,
            'total' => $total,
            'total_pages' => $totalPages,
            'page' => $page,
            'counts' => $counts,
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<SeoMedia>  $query
     */
    private function applyStatusFilter($query, ?string $statusFilter): void
    {
        $statusFilter = strtolower(trim((string) $statusFilter));

        if ($statusFilter === 'processing') {
            $query->where('status', 'processing');

            return;
        }

        if ($statusFilter === 'failed') {
            $query->where('status', 'failed');

            return;
        }

        if ($statusFilter === 'completed') {
            $query->where(function ($statusQuery): void {
                $statusQuery->whereNull('status')
                    ->orWhere('status', 'completed');
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapItem(SeoMedia $media): array
    {
        $status = strtolower(trim((string) ($media->status ?? 'completed')));
        if ($status === '') {
            $status = 'completed';
        }

        $url = (string) ($media->url ?? '');
        if (str_contains($url, 'placeholder-loading') || $status === 'processing') {
            $url = SeoMedia::placeholderLoadingUrl();
        } elseif ($url === '') {
            $url = $media->publicUrl();
        }

        $articleId = $media->firstArticleId();
        $articleEditUrl = $articleId !== null && $articleId > 0
            ? ArticleResource::getUrl('edit', ['record' => $articleId], panel: ArticleResource::panelId())
            : null;

        return [
            'id' => (int) $media->id,
            'status' => $status,
            'source' => (string) ($media->source ?? ''),
            'media_type' => $media->aiToolType(),
            'slug' => (string) ($media->slug ?? ''),
            'url' => $url,
            'error_message' => filled($media->error_message) ? (string) $media->error_message : null,
            'ai_generator' => filled($media->ai_generator) ? (string) $media->ai_generator : null,
            'editor_block_id' => filled($media->editor_block_id) ? (string) $media->editor_block_id : null,
            'article_id' => $articleId,
            'article_edit_url' => $articleEditUrl,
            'created_at' => $media->created_at instanceof Carbon
                ? $media->created_at->format('Y-m-d H:i')
                : null,
            'updated_at' => $media->updated_at instanceof Carbon
                ? $media->updated_at->format('Y-m-d H:i')
                : null,
            'is_placeholder' => $status === 'processing' || str_contains((string) ($media->url ?? ''), 'placeholder-loading'),
        ];
    }
}
