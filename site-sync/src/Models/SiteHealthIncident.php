<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

final class SiteHealthIncident extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_health_incidents';

    protected $guarded = [];

    protected $casts = [
        'diagnostic_stages' => 'array',
        'detected_at' => 'datetime',
        'last_occurred_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
