<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchFoundation\Support\IncrementalDomainSyncCache;
use Omnichannel\Addons\WordPress\Services\SyncDomainContentService;
use App\Models\Site;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class IncrementalDomainSyncRunner
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
        $state = Cache::get(IncrementalDomainSyncCache::cacheKey($userId, $siteId));

        return IncrementalDomainSyncCache::progressFromState(is_array($state) ? $state : null);
    }

    public function isRunning(int $userId, int $siteId): bool
    {
        $state = Cache::get(IncrementalDomainSyncCache::cacheKey($userId, $siteId));

        return IncrementalDomainSyncCache::isActivelyRunning(is_array($state) ? $state : null);
    }

    public function run(Site $site, int $userId): void
    {
        $siteId = (int) $site->getKey();
        $cacheKey = IncrementalDomainSyncCache::cacheKey($userId, $siteId);
        $fullItemsKey = IncrementalDomainSyncCache::fullItemsCacheKey($cacheKey);
        $state = Cache::get($cacheKey);

        if (! is_array($state) || ! is_array($state['refs'] ?? null)) {
            return;
        }

        if (($state['status'] ?? IncrementalDomainSyncCache::STATUS_RUNNING) !== IncrementalDomainSyncCache::STATUS_RUNNING) {
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
                        (string) ($result['message'] ?? __('seo-content-ai::filament.domain.sync_incremental_failed')),
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
                $state['status'] = IncrementalDomainSyncCache::STATUS_RUNNING;

                Cache::put(
                    $cacheKey,
                    IncrementalDomainSyncCache::touch($state),
                    now()->addHours(2),
                );

                if ($state['offset'] >= count($refs)) {
                    $this->markCompleted($cacheKey, $fullItemsKey, $state, $userId);

                    return;
                }
            }
        } catch (Throwable $exception) {
            Log::error('SeoContentAi incremental sync job failed', [
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
        $message = sprintf(
            'Đồng bộ bổ sung xong: %d mục mới, %d cập nhật, %d bỏ qua.',
            (int) ($state['new_count'] ?? 0),
            (int) ($state['update_count'] ?? 0),
            (int) ($state['skipped'] ?? 0),
        );

        $state['status'] = IncrementalDomainSyncCache::STATUS_COMPLETED;
        $state['message'] = $message;
        $state['offset'] = count(is_array($state['refs'] ?? null) ? $state['refs'] : []);

        Cache::put($cacheKey, $state, now()->addMinutes(30));
        Cache::forget($fullItemsKey);

        $this->notifyUser(
            $userId,
            true,
            __('seo-content-ai::filament.domain.sync_incremental_success'),
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
        $state['status'] = IncrementalDomainSyncCache::STATUS_FAILED;
        $state['message'] = $message;

        Cache::put(
            $cacheKey,
            IncrementalDomainSyncCache::touch($state),
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
            __('seo-content-ai::filament.domain.sync_incremental_failed'),
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
