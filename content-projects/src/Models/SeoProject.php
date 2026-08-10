<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SeoProject extends Model
{
    use BelongsToOnDefaultConnection;

    public const STATUS_PENDING = 'pending';

    public const STATUS_MANUAL = 'manual';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_APPROVED = 'approved';

    public const KIND_MONTHLY = 'monthly';

    public const KIND_ARCHIVE = 'archive';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_projects';

    protected $guarded = [];

    protected $casts = [
        'month' => 'date',
        'site_id' => 'integer',
        'total_tasks' => 'integer',
        'archived_at' => 'datetime',
        'archived_by' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(SeoProjectTask::class, 'project_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SeoProjectRun::class, 'project_id');
    }

    /**
     * Runs chưa bị consolidate (ẩn khỏi list UI sau Phase 3C3).
     */
    public function notConsolidatedRuns(): HasMany
    {
        return $this->hasMany(SeoProjectRun::class, 'project_id')
            ->whereNull('consolidated_into_run_id');
    }

    public function archives(): HasMany
    {
        return $this->hasMany(SeoProjectArchive::class, 'project_id');
    }

    public function currentArchive(): HasOne
    {
        return $this->hasOne(SeoProjectArchive::class, 'project_id')
            ->whereNull('restored_at')
            ->orderByDesc('id');
    }

    /**
     * @param  Builder<SeoProject>  $query
     * @return Builder<SeoProject>
     */
    public function scopeActiveProjects(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<SeoProject>  $query
     * @return Builder<SeoProject>
     */
    public function scopeArchivedProjects(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function isProjectArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isArchive(): bool
    {
        return (string) ($this->kind ?? self::KIND_MONTHLY) === self::KIND_ARCHIVE;
    }

    public function activeArticleCount(): int
    {
        if ($this->relationLoaded('tasks')) {
            return $this->tasks
                ->filter(static fn (SeoProjectTask $task): bool => $task->archived_at === null
                    && (int) ($task->article_id ?? 0) > 0)
                ->count();
        }

        return (int) $this->tasks()
            ->active()
            ->whereNotNull('article_id')
            ->where('article_id', '>', 0)
            ->count();
    }

    public function activeCompletedCount(): int
    {
        return (int) $this->tasks()
            ->active()
            ->where('status', SeoProjectTask::STATUS_COMPLETED)
            ->count();
    }

    public function monthCarbon(): Carbon
    {
        return Carbon::parse($this->month)->startOfMonth();
    }

    public function maxTasksAllowed(): int
    {
        if ($this->isArchive()) {
            return PHP_INT_MAX;
        }

        return $this->monthCarbon()->daysInMonth;
    }

    public function isExecutionMonthOpen(): bool
    {
        if ($this->isArchive()) {
            return true;
        }

        return now()->lte($this->monthCarbon()->copy()->endOfMonth()->endOfDay());
    }

    public function registeredTaskCount(): int
    {
        if ($this->relationLoaded('tasks')) {
            return $this->tasks
                ->filter(static fn (SeoProjectTask $task): bool => $task->archived_at === null
                    && (string) $task->status !== SeoProjectTask::STATUS_CANCELLED)
                ->count();
        }

        return (int) $this->tasks()->planned()->count();
    }

    public function remainingTaskCapacity(): int
    {
        if ($this->isArchive()) {
            return PHP_INT_MAX;
        }

        return max(0, $this->maxTasksAllowed() - $this->registeredTaskCount());
    }

    public function canRegisterMoreTasks(): bool
    {
        if ($this->isArchive()) {
            return true;
        }

        return $this->remainingTaskCapacity() > 0;
    }

    public function syncTotalTasksCounter(): void
    {
        $count = (int) $this->tasks()->planned()->count();

        if ((int) ($this->total_tasks ?? 0) === $count) {
            return;
        }

        $this->update(['total_tasks' => $count]);
    }

    public static function defaultNameFromMonth(Carbon|string $month): string
    {
        $carbon = Carbon::parse($month)->startOfMonth();

        return 'project '.$carbon->format('n/Y');
    }

    public static function archiveProjectName(?string $domain = null): string
    {
        $domain = trim((string) $domain);

        return $domain !== ''
            ? __('seo-content-ai::filament.projects.archive_project_name_with_domain', ['domain' => $domain])
            : __('seo-content-ai::filament.projects.archive_project_name');
    }

    public static function archiveSentinelMonth(): string
    {
        return '2000-01-01';
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_APPROVED => 'Đã duyệt',
            self::STATUS_PENDING => 'Chờ duyệt',
            self::STATUS_MANUAL => 'Thủ công',
            self::STATUS_RUNNING => 'Đang chạy',
            self::STATUS_COMPLETED => 'Hoàn thành',
            self::STATUS_PAUSED => 'Tạm dừng',
        ];
    }
}
