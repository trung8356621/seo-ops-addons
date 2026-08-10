<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSiteSyncRepairPlan extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_sync_repair_plans';

    protected $fillable = [
        'site_id',
        'public_ref',
        'status',
        'dry_run',
        'items',
        'result',
        'actor_id',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'items' => 'array',
        'result' => 'array',
    ];
}
