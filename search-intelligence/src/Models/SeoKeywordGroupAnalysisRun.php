<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;

final class SeoKeywordGroupAnalysisRun extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_group_analysis_runs';

    protected $fillable = [
        'site_id',
        'run_id',
        'language',
        'dataset_revision',
        'dataset_hash',
        'fingerprint',
        'algorithm',
        'phase',
        'group_count',
        'member_count',
        'last_error',
        'finalized_at',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'dataset_revision' => 'integer',
        'group_count' => 'integer',
        'member_count' => 'integer',
        'finalized_at' => 'datetime',
    ];
}
