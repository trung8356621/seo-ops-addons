<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSiteSyncProviderTimeline extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_sync_provider_timeline';

    protected $fillable = [
        'site_id',
        'provider',
        'provider_version',
        'edition',
        'started_at',
        'ended_at',
        'reason',
        'manifest_snippet',
    ];

    protected $casts = [
        'manifest_snippet' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];
}
