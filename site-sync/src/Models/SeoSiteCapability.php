<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSiteCapability extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_capabilities';

    protected $fillable = [
        'site_id',
        'schema_version',
        'bridge_version',
        'site_url',
        'manifest',
        'detected_at',
    ];

    protected $casts = [
        'manifest' => 'array',
        'detected_at' => 'datetime',
    ];
}
