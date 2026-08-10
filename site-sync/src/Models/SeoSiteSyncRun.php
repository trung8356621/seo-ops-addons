<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoSiteSyncRun extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_sync_runs';

    protected $fillable = [
        'site_id',
        'public_ref',
        'mode',
        'status',
        'current_step',
        'cursor',
        'run_token',
        'resumable',
        'triggered_by',
        'trigger_source',
        'counters',
        'warnings',
        'meta',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'resumable' => 'boolean',
        'counters' => 'array',
        'warnings' => 'array',
        'meta' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(SeoSiteSyncRunStep::class, 'run_id');
    }
}
