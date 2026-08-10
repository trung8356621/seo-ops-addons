<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class KeywordGroupMetricSnapshot extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'keyword_group_metric_snapshots';

    protected $guarded = [];

    protected $casts = [
        'value_int' => 'integer',
        'checked_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(KeywordRankCheckRun::class, 'run_id');
    }
}
