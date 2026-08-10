<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoSiteSyncRunStep extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_sync_run_steps';

    protected $fillable = [
        'run_id',
        'step_key',
        'step_order',
        'status',
        'attempt_count',
        'last_error_code',
        'metrics',
        'checkpoint',
        'error_message',
        'retry_after',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'metrics' => 'array',
        'checkpoint' => 'array',
        'retry_after' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SeoSiteSyncRun::class, 'run_id');
    }
}
