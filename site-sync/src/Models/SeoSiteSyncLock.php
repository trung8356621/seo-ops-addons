<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSiteSyncLock extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_sync_locks';

    protected $fillable = [
        'site_id',
        'owner_token',
        'lock_type',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
