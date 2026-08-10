<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSiteManualLink extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_manual_links';

    protected $fillable = [
        'site_id',
        'keyword',
        'url',
        'url_hash',
        'is_locked',
    ];

    protected $casts = [
        'is_locked' => 'boolean',
    ];
}
