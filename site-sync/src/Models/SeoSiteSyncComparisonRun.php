<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoSiteSyncComparisonRun extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_sync_comparison_runs';

    protected $fillable = [
        'site_id',
        'public_ref',
        'status',
        'scope',
        'blocking_count',
        'needs_review_count',
        'expected_count',
        'summary',
        'export_path',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function diffs(): HasMany
    {
        return $this->hasMany(SeoSiteSyncComparisonDiff::class, 'run_id');
    }
}
