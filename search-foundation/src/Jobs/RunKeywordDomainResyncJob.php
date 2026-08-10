<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Jobs;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordDomainResyncService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\SearchFoundation\Support\KeywordDomainResyncCache;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RunKeywordDomainResyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public int $siteId,
        public int $userId,
    ) {}

    public function handle(
        KeywordDomainResyncService $resyncService,
        SeoDatabaseConnectionService $databaseConnection,
    ): void {
        $databaseConnection->bootstrapSeoDatabaseConnection($this->siteId);

        KeywordDomainResyncCache::markHandleStarted($this->userId, $this->siteId);

        $user = User::query()->find($this->userId);
        if ($user !== null) {
            auth()->setUser($user);
        }

        $result = $resyncService->resetAndResync($this->siteId);

        KeywordDomainResyncCache::markCompleted($this->userId, $this->siteId, $result);

        $this->notifyUser(
            success: true,
            title: __('seo-content-ai::filament.keyword.resync_linked_completed'),
            body: __('seo-content-ai::filament.keyword.resync_linked_body', $result),
        );
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception?->getMessage()
            ?? __('seo-content-ai::filament.keyword.resync_linked_failed');

        KeywordDomainResyncCache::markFailed($this->userId, $this->siteId, $message);

        $this->notifyUser(
            success: false,
            title: __('seo-content-ai::filament.keyword.resync_linked_failed'),
            body: $message,
        );
    }

    private function notifyUser(bool $success, string $title, string $body): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body !== '' ? $body : ' ');

        $success ? $notification->success() : $notification->danger();

        $notification->sendToDatabase($user);
    }
}
