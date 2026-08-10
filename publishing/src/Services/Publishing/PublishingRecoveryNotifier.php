<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Publishing recovery notifications for Filament Notification Center (database).
 * Dedup by publishing-recovery:{tenant}:{project}:{batch}.
 */
final class PublishingRecoveryNotifier
{
    /**
     * @param  Collection<int, SeoProjectTask>  $tasks
     */
    public function notifyStuckDetected(Collection $tasks, string $batchId): void
    {
        if ($tasks->isEmpty() || ! Schema::hasTable('notifications')) {
            return;
        }

        $count = $tasks->count();
        $project = $tasks->first()?->project;
        $projectId = (int) ($project?->getKey() ?? $tasks->first()?->project_id ?? 0);
        $recipients = $this->recipientsForProject($project instanceof SeoProject ? $project : null, $projectId);
        if ($recipients->isEmpty()) {
            return;
        }

        $dedup = $this->dedupKey($projectId, $batchId);
        $url = $this->queueUrl($projectId, 'publishing');

        foreach ($recipients as $user) {
            $this->upsertProgress($user, $dedup, [
                'title' => "{$count} bài publish bị gián đoạn",
                'body' => 'Hệ thống đang kiểm tra WordPress và tự khôi phục.',
                'status' => 'warning',
                'url' => $url,
                'progress' => "0/{$count}",
                'phase' => 'stuck_detected',
            ]);
        }
    }

    public function notifyBatchProgress(
        int $projectId,
        string $batchId,
        int $published,
        int $retryWait,
        int $failed,
        int $total,
    ): void {
        if ($total <= 0 || ! Schema::hasTable('notifications')) {
            return;
        }

        $project = SeoProject::query()->find($projectId);
        $recipients = $this->recipientsForProject($project, $projectId);
        if ($recipients->isEmpty()) {
            return;
        }

        $done = $published + $retryWait + $failed;
        $dedup = $this->dedupKey($projectId, $batchId);
        $url = $this->queueUrl($projectId, $failed > 0 ? 'failed' : 'publishing');
        $title = $retryWait > 0
            ? "Đang thử lại {$retryWait} bài publish"
            : "Đã xử lý {$done}/{$total}";
        $body = "Đã xử lý {$done}/{$total}. Đang retry {$retryWait}. Cần xử lý {$failed}.";

        foreach ($recipients as $user) {
            $this->upsertProgress($user, $dedup, [
                'title' => $title,
                'body' => $body,
                'status' => $failed > 0 ? 'warning' : 'info',
                'url' => $url,
                'progress' => "{$done}/{$total}",
                'phase' => 'progress',
            ]);
        }
    }

    /**
     * @param  Collection<int, SeoProjectTask>  $tasks
     * @param  array<string, mixed>  $stats
     */
    public function notifyBatchFinished(Collection $tasks, string $batchId, array $stats): void
    {
        if ($tasks->isEmpty() || ! Schema::hasTable('notifications')) {
            return;
        }

        $project = $tasks->first()?->project;
        $projectId = (int) ($project?->getKey() ?? $tasks->first()?->project_id ?? 0);
        $recipients = $this->recipientsForProject($project instanceof SeoProject ? $project : null, $projectId);
        if ($recipients->isEmpty()) {
            return;
        }

        $published = (int) ($stats['published'] ?? 0);
        $failed = (int) ($stats['failed'] ?? 0);
        $retryWait = (int) ($stats['retry_wait'] ?? 0);
        $scanned = (int) ($stats['scanned'] ?? $tasks->count());
        $dedup = $this->dedupKey($projectId, $batchId);
        $url = $this->queueUrl($projectId, $failed > 0 ? 'failed' : 'published');

        if ($failed > 0) {
            $title = "{$failed} bài publish thất bại sau 3 lần thử";
            $body = 'Cần kiểm tra lỗi kết nối hoặc cấu hình WordPress.';
            $status = 'danger';
        } elseif ($published > 0 && $retryWait === 0) {
            $title = "Đã khôi phục thành công {$published}/{$scanned} bài publish.";
            $body = $published > 0 && $scanned === $published
                ? 'Laravel đã đồng bộ lại trạng thái mà không gửi publish lần nữa.'
                : "Published {$published}, còn xử lý khác đã lên lịch retry.";
            $status = 'success';
        } elseif ($published > 0) {
            $title = "{$published} bài đã publish trên WordPress";
            $body = 'Laravel đã đồng bộ lại trạng thái mà không gửi publish lần nữa.';
            $status = 'success';
        } else {
            $title = "Đang thử lại {$retryWait} bài publish";
            $body = 'Lần tiếp theo theo backoff 5/15/30 phút nếu vẫn thất bại.';
            $status = 'warning';
        }

        foreach ($recipients as $user) {
            $this->upsertProgress($user, $dedup, [
                'title' => $title,
                'body' => $body,
                'status' => $status,
                'url' => $url,
                'progress' => "{$published}/{$scanned}",
                'phase' => 'finished',
            ]);
        }
    }

    /**
     * @param  array{title: string, body: string, status: string, url: string, progress: string, phase: string}  $payload
     */
    private function upsertProgress(User $user, string $dedupKey, array $payload): void
    {
        $existing = DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where('data->dedup_key', $dedupKey)
            ->orderByDesc('created_at')
            ->first();

        $notification = Notification::make()
            ->title($payload['title'])
            ->body($payload['body'])
            ->icon('heroicon-o-arrow-path')
            ->actions([
                Action::make('open_queue')
                    ->label('Xem Publishing Queue')
                    ->url($payload['url'])
                    ->button(),
            ]);

        match ($payload['status']) {
            'success' => $notification->success(),
            'danger' => $notification->danger(),
            'warning' => $notification->warning(),
            default => $notification->info(),
        };

        if ($existing instanceof DatabaseNotification) {
            $data = is_array($existing->data) ? $existing->data : [];
            $data['title'] = $payload['title'];
            $data['body'] = $payload['body'];
            $data['status'] = $payload['status'];
            $data['dedup_key'] = $dedupKey;
            $data['progress'] = $payload['progress'];
            $data['phase'] = $payload['phase'];
            $data['actions'] = [
                [
                    'name' => 'open_queue',
                    'label' => 'Xem Publishing Queue',
                    'url' => $payload['url'],
                ],
            ];
            $existing->forceFill([
                'data' => $data,
                'read_at' => null,
            ])->save();

            return;
        }

        $notification
            ->viewData(['dedup_key' => $dedupKey])
            ->sendToDatabase($user);

        // Persist dedup_key into stored JSON (Filament may not keep viewData).
        DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->orderByDesc('created_at')
            ->limit(1)
            ->get()
            ->each(static function (DatabaseNotification $row) use ($dedupKey, $payload): void {
                $data = is_array($row->data) ? $row->data : [];
                if (($data['title'] ?? null) !== $payload['title']) {
                    return;
                }
                $data['dedup_key'] = $dedupKey;
                $data['progress'] = $payload['progress'];
                $data['phase'] = $payload['phase'];
                $row->forceFill(['data' => $data])->save();
            });
    }

    /**
     * @return Collection<int, User>
     */
    private function recipientsForProject(?SeoProject $project, int $projectId): Collection
    {
        $ownerId = (int) ($project?->user_id ?? 0);
        $siteId = (int) ($project?->site_id ?? 0);
        $users = collect();

        if ($ownerId > 0) {
            $owner = User::query()->find($ownerId);
            if ($owner instanceof User && $this->canReceivePublishAlerts($owner)) {
                $users->push($owner);
            }
        }

        // Planners / content managers under same account owner who can manage workflow.
        if ($ownerId > 0) {
            $owner = User::query()->find($ownerId);
            if ($owner instanceof User) {
                $accountOwnerId = $owner->isStaff() ? (int) $owner->parent_id : (int) $owner->id;
                User::query()
                    ->where('status', User::STATUS_NORMAL)
                    ->whereIn('seo_role', [
                        User::SEO_ROLE_PLANNER,
                        User::SEO_ROLE_CONTENT_MANAGER,
                    ])
                    ->where(function ($q) use ($accountOwnerId): void {
                        $q->whereKey($accountOwnerId)->orWhere('parent_id', $accountOwnerId);
                    })
                    ->get()
                    ->each(function (User $user) use ($users): void {
                        if ($this->canReceivePublishAlerts($user)) {
                            $users->push($user);
                        }
                    });
            }
        }

        unset($siteId, $projectId);

        return $users->unique(static fn (User $u): int => (int) $u->getKey())->values();
    }

    private function canReceivePublishAlerts(User $user): bool
    {
        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return true;
        }

        return in_array((string) ($user->seo_role ?? ''), [
            User::SEO_ROLE_PLANNER,
            User::SEO_ROLE_CONTENT_MANAGER,
        ], true);
    }

    private function dedupKey(int $projectId, string $batchId): string
    {
        $tenant = (string) (SeoConnectionContext::current()?->getKey() ?? 'global');

        return sprintf('publishing-recovery:%s:%d:%s', $tenant, $projectId, $batchId);
    }

    private function queueUrl(int $projectId, string $status): string
    {
        $path = 'publishing-queue';
        $query = http_build_query(array_filter([
            'projectId' => $projectId > 0 ? $projectId : null,
            'status' => $status !== '' ? $status : null,
        ]));

        return SeoConnectionContext::panelUrl($path.($query !== '' ? '?'.$query : ''));
    }
}
