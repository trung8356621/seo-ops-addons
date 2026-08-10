<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSiteSyncComparisonDiff extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_sync_comparison_diffs';

    protected $fillable = [
        'run_id',
        'site_id',
        'group_key',
        'entity_key',
        'classification',
        'reason_code',
        'message',
        'legacy_value',
        'v2_value',
    ];

    protected $casts = [
        'legacy_value' => 'array',
        'v2_value' => 'array',
    ];
}
