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

    /** Planning pool — generation / publishing / scheduling disabled. */
    public const STATUS_DRAFT = 'draft';

    public const KIND_MONTHLY = 'monthly';

    public const KIND_ARCHIVE = 'archive';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_projects';

    protected $guarded = [];

    protected $casts = [
        'month' => 'date',
        'site_id' => 'integer',
        'total_tasks' => 'integer',
        'user_id' => 'integer',
        'source_draft_project_id' => 'integer',
        'archived_at' => 'datetime',
        'archived_by' => 'integer',
        'meta' => 'array',
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

    /**
     * Draft planning pool: unlimited items, no execution month gate, no generate/publish.
     * Not a project kind — status only.
     */
    public function isDraftPlanning(): bool
    {
        return (string) ($this->status ?? '') === self::STATUS_DRAFT
            && ! $this->isProjectArchived()
            && ! $this->isArchive();
    }

    /**
     * Compatibility month for Draft when UI does not ask for an execution month.
     * Not an execution contract — capacity/gates must use isDraftPlanning().
     */
    public static function draftCompatibilityMonth(): string
    {
        return Carbon::now()->startOfMonth()->format('Y-m-d');
    }

    /**
     * Canonical Shared Planning Draft display name.
     * Domain must NOT be bound into the Draft project name — items own site_id.
     *
     * @param  string|null  $domain  Ignored (legacy signature kept for callers).
     */
    public static function defaultDraftName(?string $domain = null): string
    {
        try {
            $label = (string) __('seo-content-ai::filament.projects.content_planning_draft_label');
            if (
                $label !== ''
                && $label !== 'seo-content-ai::filament.projects.content_planning_draft_label'
            ) {
                return $label;
            }
        } catch (\Throwable) {
            // Pure PHPUnit / no translator.
        }

        return 'Planning Draft';
    }

    /**
     * List column: archived rows use snapshot; live rows use withCount.
     */
    public function displayGeneratedCount(): int
    {
        if ($this->isProjectArchived()) {
            $archive = $this->currentArchive;
            if ($archive instanceof SeoProjectArchive) {
                return (int) ($archive->completed_articles ?? 0);
            }
        }

        return (int) ($this->getAttribute('active_generated_count') ?? 0);
    }

    public function displayPendingCount(): int
    {
        if ($this->isProjectArchived()) {
            $archive = $this->currentArchive;
            if ($archive instanceof SeoProjectArchive) {
                $summary = is_array($archive->summary_snapshot) ? $archive->summary_snapshot : [];
                if (array_key_exists('incomplete_articles', $summary)) {
                    return max(0, (int) $summary['incomplete_articles']);
                }

                $total = (int) ($archive->total_articles ?? $archive->articles_count ?? 0);

                return max(0, $total - (int) ($archive->completed_articles ?? 0));
            }
        }

        return (int) ($this->getAttribute('active_pending_count') ?? 0);
    }

    public function displayFailedCount(): int
    {
        if ($this->isProjectArchived()) {
            $archive = $this->currentArchive;
            if ($archive instanceof SeoProjectArchive) {
                $summary = is_array($archive->summary_snapshot) ? $archive->summary_snapshot : [];
                if (array_key_exists('failed_articles', $summary)) {
                    return max(0, (int) $summary['failed_articles']);
                }
            }
        }

        return (int) ($this->getAttribute('active_failed_count') ?? 0);
    }

    /**
     * True when any active item is already linked to a local article.
     * Changing project.site_id in that state would silently corrupt ownership.
     */
    public function hasLinkedOrGeneratedArticles(): bool
    {
        return $this->tasks()
            ->whereNull('archived_at')
            ->whereNotNull('article_id')
            ->where('article_id', '>', 0)
            ->exists();
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

    /**
     * Soft capacity API — days-in-month is NOT a hard task limit.
     * Month is execution/reporting period only. Callers must not block writes on this.
     */
    public function maxTasksAllowed(): int
    {
        return PHP_INT_MAX;
    }

    public function isExecutionMonthOpen(): bool
    {
        if ($this->isArchive() || $this->isDraftPlanning()) {
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

    /**
     * @deprecated Capacity is unlimited. Always PHP_INT_MAX for non-cancelled active items math.
     */
    public function remainingTaskCapacity(): int
    {
        return PHP_INT_MAX;
    }

    /**
     * @deprecated Capacity is unlimited. Always true when project is not an archive vault.
     */
    public function canRegisterMoreTasks(): bool
    {
        return ! $this->isArchive();
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

    public static function defaultExecutionName(Carbon|string $month, ?string $domain = null): string
    {
        $carbon = Carbon::parse($month)->startOfMonth();
        $label = 'Content execution — '.$carbon->format('m/Y');
        $domain = trim((string) $domain);

        return $domain !== '' ? $label.' — '.$domain : $label;
    }

    public function sourceDraft(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_draft_project_id');
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
            self::STATUS_DRAFT => (string) __('seo-content-ai::filament.projects.status_draft'),
            self::STATUS_APPROVED => (string) __('seo-content-ai::filament.projects.status_approved'),
            self::STATUS_PENDING => (string) __('seo-content-ai::filament.projects.status_pending'),
            self::STATUS_MANUAL => (string) __('seo-content-ai::filament.projects.status_manual'),
            self::STATUS_RUNNING => (string) __('seo-content-ai::filament.projects.status_running'),
            self::STATUS_COMPLETED => (string) __('seo-content-ai::filament.projects.status_completed'),
            self::STATUS_PAUSED => (string) __('seo-content-ai::filament.projects.status_paused'),
        ];
    }
}
