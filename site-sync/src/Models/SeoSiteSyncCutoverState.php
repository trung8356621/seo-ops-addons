<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSiteSyncCutoverState extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_sync_cutover_states';

    protected $fillable = [
        'site_id',
        'mode',
        'previous_mode',
        'checkpoint_id',
        'shadow_started_at',
        'activated_at',
        'rolled_back_at',
        'metrics',
        'meta',
    ];

    protected $casts = [
        'metrics' => 'array',
        'meta' => 'array',
        'shadow_started_at' => 'datetime',
        'activated_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];
}
