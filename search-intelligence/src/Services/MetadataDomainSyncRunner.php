<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchFoundation\Support\MetadataDomainSyncCache;
use Omnichannel\Addons\WordPress\Services\SyncDomainContentService;
use App\Models\Site;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MetadataDomainSyncRunner
{
    public function __construct(
        private SyncDomainContentService $syncService,
    ) {}

    /**
     * @return array{
     *     done: int,
     *     total: int,
     *     status: string,
     *     running: bool,
     *     message: ?string
     * }
     */
    public function readProgress(int $userId, int $siteId): array
    {
        $state = Cache::get(MetadataDomainSyncCache::cacheKey($userId, $siteId));

        return MetadataDomainSyncCache::progressFromState(is_array($state) ? $state : null);
    }

    public function isRunning(int $userId, int $siteId): bool
    {
        $state = Cache::get(MetadataDomainSyncCache::cacheKey($userId, $siteId));

        return MetadataDomainSyncCache::isActivelyRunning(is_array($state) ? $state : null);
    }

    public function run(Site $site, int $userId): void
    {
        $siteId = (int) $site->getKey();
        $cacheKey = MetadataDomainSyncCache::cacheKey($userId, $siteId);
        $fullItemsKey = MetadataDomainSyncCache::fullItemsCacheKey($cacheKey);
        $state = Cache::get($cacheKey);

        if (! is_array($state) || ! is_array($state['refs'] ?? null)) {
            return;
        }

        if (($state['status'] ?? MetadataDomainSyncCache::STATUS_RUNNING) !== MetadataDomainSyncCache::STATUS_RUNNING) {
            return;
        }

        $refs = $state['refs'];
        $chunkSize = $this->syncService->incrementalSyncChunkSize();

        try {
            while (true) {
                $offset = (int) ($state['offset'] ?? 0);
                $chunkRefs = array_slice($refs, $offset, $chunkSize);

                if ($chunkRefs === []) {
                    $this->markCompleted($cacheKey, $fullItemsKey, $state, $userId);

                    return;
                }

                $chunkState = is_array($state['chunk_state'] ?? null) ? $state['chunk_state'] : [];
                $cachedFullItems = Cache::get($fullItemsKey);
                if (is_array($cachedFullItems)) {
                    $chunkState['full_sync_items'] = $cachedFullItems;
                }

                $result = $this->syncService->processIncrementalChunk($site, $chunkRefs, $chunkState);

                if (! ($result['success'] ?? false)) {
                    $this->markFailed(
                        $cacheKey,
                        $fullItemsKey,
                        $state,
                        $userId,
                        (string) ($result['message'] ?? __('seo-content-ai::filament.domain.sync_metadata_failed')),
                    );

                    return;
                }

                $state['offset'] = $offset + count($chunkRefs);
                $updatedChunkState = is_array($result['state'] ?? null) ? $result['state'] : $chunkState;

                if (is_array($updatedChunkState['full_sync_items'] ?? null)) {
                    Cache::put($fullItemsKey, $updatedChunkState['full_sync_items'], now()->addHours(2));
                    unset($updatedChunkState['full_sync_items']);
                }

                $state['chunk_state'] = $updatedChunkState;
                $state['accumulated_synced'] = $this->syncService->mergeSyncedCounts(
                    is_array($state['accumulated_synced'] ?? null) ? $state['accumulated_synced'] : [],
                    is_array($result['synced'] ?? null) ? $result['synced'] : [],
                );
                $state['status'] = MetadataDomainSyncCache::STATUS_RUNNING;

                Cache::put(
                    $cacheKey,
                    MetadataDomainSyncCache::touch($state),
                    now()->addHours(2),
                );

                if ($state['offset'] >= count($refs)) {
                    $this->markCompleted($cacheKey, $fullItemsKey, $state, $userId);

                    return;
                }
            }
        } catch (Throwable $exception) {
            Log::error('SeoContentAi metadata resync job failed', [
                'site_id' => $siteId,
                'user_id' => $userId,
                'error' => $exception->getMessage(),
            ]);

            $this->markFailed(
                $cacheKey,
                $fullItemsKey,
                $state,
                $userId,
                $exception->getMessage(),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function markCompleted(string $cacheKey, string $fullItemsKey, array $state, int $userId): void
    {
        $synced = is_array($state['accumulated_synced'] ?? null) ? $state['accumulated_synced'] : [];
        $imported = array_sum($synced);
        $message = sprintf(
            'Đã cập nhật thành phần cho %d mục (ngôn ngữ, Polylang, SEO meta, trạng thái…).',
            $imported,
        );

        $state['status'] = MetadataDomainSyncCache::STATUS_COMPLETED;
        $state['message'] = $message;
        $state['offset'] = count(is_array($state['refs'] ?? null) ? $state['refs'] : []);

        Cache::put($cacheKey, $state, now()->addMinutes(30));
        Cache::forget($fullItemsKey);

        $this->notifyUser(
            $userId,
            true,
            __('seo-content-ai::filament.domain.sync_metadata_success'),
            $message,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function markFailed(
        string $cacheKey,
        string $fullItemsKey,
        array $state,
        int $userId,
        string $message,
    ): void {
        $state['status'] = MetadataDomainSyncCache::STATUS_FAILED;
        $state['message'] = $message;

        Cache::put(
            $cacheKey,
            MetadataDomainSyncCache::touch($state),
            now()->addHours(2),
        );

        $total = count(is_array($state['refs'] ?? null) ? $state['refs'] : []);
        $offset = (int) ($state['offset'] ?? 0);
        if ($offset >= $total) {
            Cache::forget($fullItemsKey);
        }

        $this->notifyUser(
            $userId,
            false,
            __('seo-content-ai::filament.domain.sync_metadata_failed'),
            $message,
        );
    }

    private function notifyUser(int $userId, bool $success, string $title, string $message): void
    {
        $user = User::query()->find($userId);
        if ($user === null) {
            return;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($message !== '' ? $message : ' ');

        $success ? $notification->success() : $notification->danger();

        $notification->sendToDatabase($user);
    }
}
