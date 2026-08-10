<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;

final class KeywordRankCheckRun extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'keyword_rank_check_runs';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
