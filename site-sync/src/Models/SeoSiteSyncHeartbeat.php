<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSiteSyncHeartbeat extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_sync_heartbeats';

    protected $fillable = [
        'channel',
        'last_seen_at',
        'meta',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'meta' => 'array',
    ];
}
