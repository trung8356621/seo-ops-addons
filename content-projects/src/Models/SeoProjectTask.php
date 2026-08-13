<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SeoProjectTask extends Model
{
    use BelongsToOnDefaultConnection;
    use SoftDeletes;

    public const TYPE_CREATE = 'create';

    public const TYPE_REWRITE = 'rewrite';

    public const TYPE_IMPROVE = 'improve';

    /** @deprecated Use TYPE_CREATE — kept for legacy form/payload aliases. */
    public const TYPE_NEW_KEYWORD = 'create';

    /** @deprecated Use TYPE_CREATE — kept for legacy form/payload aliases. */
    public const TYPE_NEW_TITLE = 'create';

    public const REWRITE_MODE_KEYWORD = 'keyword';

    public const REWRITE_MODE_CONTENT = 'content';

    public const POST_TYPE_ARTICLE = 'article';

    public const POST_TYPE_PRODUCT = 'product';

    public const POST_TYPE_CATEGORY = 'category';

    public const POST_TYPE_PRODUCT_CATEGORY = 'product_category';

    public const STATUS_PENDING = 'pending';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_WRITING = 'writing';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_CANCELLED = 'cancelled';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_project_tasks';

    protected $guarded = [];

    protected $casts = [
        'site_id' => 'integer',
        'article_id' => 'integer',
        'archived_from_project_id' => 'integer',
        'target_date' => 'date',
        'scheduled_publish_at' => 'datetime',
        'publish_retry_count' => 'integer',
        'publish_attempt_count' => 'integer',
        'dispatch_count' => 'integer',
        'last_publish_attempt_at' => 'datetime',
        'publishing_started_at' => 'datetime',
        'delivery_dispatched_at' => 'datetime',
        'publisher_started_at' => 'datetime',
        'publish_lease_expires_at' => 'datetime',
        'next_publish_retry_at' => 'datetime',
        'last_publish_failed_at' => 'datetime',
        'last_publish_http_status' => 'integer',
        'publish_published_at' => 'datetime',
        'connected_at' => 'datetime',
        'completed_at' => 'datetime',
        'content_manager_reviewed_at' => 'datetime',
        'content_manager_reviewed_by' => 'integer',
        'publishing_queued_at' => 'datetime',
        'publishing_queued_by' => 'integer',
        'generation_blocked_at' => 'datetime',
        'generation_blocked_by' => 'integer',
        'archived_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Active = chưa archive. SoftDeletes tự loại deleted_at.
     *
     * @param  Builder<SeoProjectTask>  $query
     * @return Builder<SeoProjectTask>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Canonical eligibility for Generate / retry / resume / bulk selection.
     * planned() + not operator-blocked.
     *
     * @param  Builder<SeoProjectTask>  $query
     * @return Builder<SeoProjectTask>
     */
    public function scopeEligibleForGeneration(Builder $query): Builder
    {
        $query = $query->planned();
        try {
            if (\Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'generation_blocked_at')) {
                $query->whereNull('generation_blocked_at');
            }
        } catch (\Throwable) {
            // Schema unavailable — keep planned set.
        }

        return $query;
    }

    public function isGenerationBlocked(): bool
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'generation_blocked_at')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return $this->generation_blocked_at !== null;
    }

    public function isEligibleForGeneration(): bool
    {
        if ($this->archived_at !== null || (string) $this->status === self::STATUS_CANCELLED) {
            return false;
        }

        return ! $this->isGenerationBlocked();
    }

    /**
     * Content Project working set — not handed to Publishing Queue.
     *
     * @param  Builder<SeoProjectTask>  $query
     * @return Builder<SeoProjectTask>
     */
    public function scopeInContentProjectWorkingSet(Builder $query): Builder
    {
        $query = $query->active();
        try {
            if (\Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publishing_queued_at')) {
                $query->whereNull('publishing_queued_at');
            }
        } catch (\Throwable) {
            // Schema unavailable — keep active set.
        }

        return $query;
    }

    /**
     * Publishing Queue module ownership.
     *
     * @param  Builder<SeoProjectTask>  $query
     * @return Builder<SeoProjectTask>
     */
    public function scopeInPublishingQueue(Builder $query): Builder
    {
        $query = $query->active();
        try {
            if (\Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publishing_queued_at')) {
                $query->whereNotNull('publishing_queued_at');
            } else {
                $query->whereRaw('0 = 1');
            }
        } catch (\Throwable) {
            $query->whereRaw('0 = 1');
        }

        return $query;
    }

    /**
     * Archived = đã archive, chưa soft-delete.
     *
     * @param  Builder<SeoProjectTask>  $query
     * @return Builder<SeoProjectTask>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * Project plan UI — active lifecycle và chưa cancelled.
     *
     * @param  Builder<SeoProjectTask>  $query
     * @return Builder<SeoProjectTask>
     */
    public function scopePlanned(Builder $query): Builder
    {
        return $query
            ->whereNull('archived_at')
            ->where('status', '!=', self::STATUS_CANCELLED);
    }

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(SeoProject::class, 'project_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }

    public function runItems(): HasMany
    {
        return $this->hasMany(SeoProjectRunItem::class, 'task_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SeoProjectTaskEvent::class, 'task_id');
    }

    /**
     * @return array<string, string>
     */
    public static function rewriteModeOptions(): array
    {
        return [
            self::REWRITE_MODE_KEYWORD => __('seo-content-ai::filament.projects.rewrite_mode_keyword'),
            self::REWRITE_MODE_CONTENT => __('seo-content-ai::filament.projects.rewrite_mode_content'),
        ];
    }

    public static function normalizeRewriteMode(mixed $value): string
    {
        $normalized = trim((string) $value);

        return in_array($normalized, [self::REWRITE_MODE_KEYWORD, self::REWRITE_MODE_CONTENT], true)
            ? $normalized
            : self::REWRITE_MODE_KEYWORD;
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_CREATE => __('seo-content-ai::filament.projects.type_create'),
            self::TYPE_REWRITE => __('seo-content-ai::filament.projects.type_rewrite'),
            self::TYPE_IMPROVE => __('seo-content-ai::filament.projects.type_improve'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function typeKeys(): array
    {
        return [
            self::TYPE_CREATE,
            self::TYPE_REWRITE,
            self::TYPE_IMPROVE,
        ];
    }

    public static function normalizeType(mixed $value): string
    {
        $normalized = trim((string) $value);

        return match ($normalized) {
            self::TYPE_REWRITE, 'Viết lại (Sửa bài lỗi)' => self::TYPE_REWRITE,
            self::TYPE_IMPROVE => self::TYPE_IMPROVE,
            'new_keyword', 'new_title', self::TYPE_CREATE,
            'Viết mới (Từ khóa)', 'Viết mới (Tiêu đề)' => self::TYPE_CREATE,
            default => self::TYPE_CREATE,
        };
    }

    /**
     * @return list<string>
     */
    public static function newArticleTypes(): array
    {
        return [self::TYPE_CREATE];
    }

    public static function isNewArticleType(mixed $type): bool
    {
        return self::normalizeType($type) === self::TYPE_CREATE;
    }

    public static function isManualRunType(string $type): bool
    {
        // Improve chạy Prompt Improve (rewrite workflow), không còn manual-only.
        return false;
    }

    public static function deriveSourceContent(
        string $type,
        ?string $keyword,
        ?string $title,
        ?string $existingArticleTitle = null,
    ): string {
        $normalized = self::normalizeType($type);

        if (in_array($normalized, [self::TYPE_REWRITE, self::TYPE_IMPROVE], true)) {
            return trim((string) $existingArticleTitle);
        }

        $keyword = trim((string) $keyword);
        if ($keyword !== '') {
            return $keyword;
        }

        return trim((string) $title);
    }

    /**
     * @return array{keyword: string, title: string, secondary_description: string}
     */
    public static function promptInputFields(
        ?string $keyword,
        ?string $title,
        ?string $secondaryDescription,
    ): array {
        return [
            'keyword' => trim((string) $keyword),
            'title' => trim((string) $title),
            'secondary_description' => trim((string) $secondaryDescription),
        ];
    }

    /**
     * @return list<string>
     */
    public static function articlePickerTypes(): array
    {
        return [self::TYPE_REWRITE, self::TYPE_IMPROVE];
    }

    /**
     * Rewrite / improve require a linked Existing Article before generation.
     *
     * @return list<string>
     */
    public static function typesRequiringExistingArticle(): array
    {
        return self::articlePickerTypes();
    }

    /**
     * @return list<string>
     */
    public static function postTypeKeys(): array
    {
        return [
            self::POST_TYPE_ARTICLE,
            self::POST_TYPE_PRODUCT,
            self::POST_TYPE_CATEGORY,
            self::POST_TYPE_PRODUCT_CATEGORY,
        ];
    }

    public static function normalizePostType(mixed $value): string
    {
        $normalized = trim((string) $value);

        return in_array($normalized, self::postTypeKeys(), true)
            ? $normalized
            : self::POST_TYPE_ARTICLE;
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_PENDING => 'Chờ làm',
            self::STATUS_WRITING => 'Đang viết',
            self::STATUS_REVIEWING => 'Đang duyệt',
            self::STATUS_COMPLETED => 'Hoàn thành',
            self::STATUS_FAILED => 'Lỗi',
        ];
    }
}
