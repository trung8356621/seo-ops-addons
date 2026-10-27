<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SeoContentGlobalBatchItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_CANCELLED = 'cancelled';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_global_batch_items';

    protected $guarded = [];

    protected $casts = [
        'batch_id' => 'integer',
        'position' => 'integer',
        'project_id' => 'integer',
        'task_id' => 'integer',
        'project_run_id' => 'integer',
        'run_item_id' => 'integer',
        'attempt_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SeoContentGlobalBatch::class, 'batch_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(SeoProject::class, 'project_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(SeoProjectTask::class, 'task_id');
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED,
            self::STATUS_CANCELLED,
        ], true);
    }
}
