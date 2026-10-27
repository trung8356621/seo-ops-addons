<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SeoContentGlobalBatch extends Model
{
    use BelongsToOnDefaultConnection;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_STOPPING = 'stopping';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_global_batches';

    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'planning_month' => 'date',
        'total_items' => 'integer',
        'succeeded' => 'integer',
        'failed' => 'integer',
        'skipped' => 'integer',
        'pending' => 'integer',
        'current_project_id' => 'integer',
        'current_project_run_id' => 'integer',
        'current_batch_item_id' => 'integer',
        'stop_requested_at' => 'datetime',
        'stop_requested_by' => 'integer',
        'project_order' => 'array',
        'settings' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SeoContentGlobalBatchItem::class, 'batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'user_id');
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }

    public function isActive(): bool
    {
        return in_array((string) $this->status, [
            self::STATUS_PENDING,
            self::STATUS_RUNNING,
            self::STATUS_STOPPING,
        ], true);
    }

    public function stopRequested(): bool
    {
        return $this->stop_requested_at !== null
            || (string) $this->status === self::STATUS_STOPPING;
    }
}
