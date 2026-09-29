<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

final class SiteHealthState extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_health_states';

    protected $guarded = [];

    protected $casts = [
        'diagnostic_stages' => 'array',
        'first_failure_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'last_success_at' => 'datetime',
    ];
}
