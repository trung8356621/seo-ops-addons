<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordAnalysisStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoKeywordAnalysisOperation extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_analysis_operations';

    protected $guarded = [];

    protected $casts = [
        'workspace_id' => 'integer',
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'stage' => KeywordAnalysisStage::class,
        'progress' => 'integer',
        'progress_percent' => 'integer',
        'total_keywords' => 'integer',
        'processed_keywords' => 'integer',
        'failed_keywords' => 'integer',
        'warnings_count' => 'integer',
        'cancel_requested' => 'boolean',
        'options' => 'array',
        'keyword_scope' => 'array',
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_by' => 'integer',
    ];

    /** @return BelongsTo<SeoKeywordWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordWorkspace::class, 'workspace_id');
    }
}
