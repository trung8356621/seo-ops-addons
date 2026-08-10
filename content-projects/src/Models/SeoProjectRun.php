<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use Omnichannel\Addons\ContentProjects\Support\ContentProjectRunSettings;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoProjectRun extends Model
{
    use BelongsToOnDefaultConnection;

    public const MODE_FULL = 'full';

    public const MODE_TEST = 'test';

    public const STATUS_RUNNING = 'running';

    /** Cooperative stop in progress — DB-first; do not map to completed. */
    public const STATUS_STOPPING = 'stopping';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_project_runs';

    protected $guarded = [];

    protected $casts = [
        'project_id' => 'integer',
        'user_id' => 'integer',
        'total' => 'integer',
        'succeeded' => 'integer',
        'failed' => 'integer',
        'items' => 'array',
        'settings' => 'array',
        'consolidated_into_run_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'consolidated_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(SeoProject::class, 'project_id');
    }

    public function consolidatedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'consolidated_into_run_id');
    }

    public function scopeNotConsolidated($query)
    {
        return $query->whereNull($query->getModel()->getTable().'.consolidated_into_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'user_id');
    }

    public function runItems(): HasMany
    {
        return $this->hasMany(SeoProjectRunItem::class, 'run_id');
    }

    public function isTestMode(): bool
    {
        return $this->mode === self::MODE_TEST;
    }

    public function runSettings(): ContentProjectRunSettings
    {
        return ContentProjectRunSettings::fromRun($this);
    }
}
