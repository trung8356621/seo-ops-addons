<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSiteSyncCheckpoint extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_sync_checkpoints';

    protected $fillable = [
        'site_id',
        'purpose',
        'from_mode',
        'to_mode',
        'actor_type',
        'actor_id',
        'reason',
        'snapshot',
    ];

    protected $casts = [
        'snapshot' => 'array',
    ];
}
