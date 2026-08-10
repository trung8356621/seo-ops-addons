<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordCannibalizationIssueStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordCannibalizationIssueType;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordCannibalizationRiskLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoKeywordCannibalizationIssue extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_cannibalization_issues';

    protected $guarded = [];

    protected $casts = [
        'workspace_id' => 'integer',
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'issue_type' => KeywordCannibalizationIssueType::class,
        'risk_level' => KeywordCannibalizationRiskLevel::class,
        'status' => KeywordCannibalizationIssueStatus::class,
        'keyword_refs' => 'array',
        'cluster_refs' => 'array',
        'article_refs' => 'array',
        'reason_codes' => 'array',
        'confidence' => 'decimal:2',
        'detected_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'resolved_by' => 'integer',
    ];

    /** @return BelongsTo<SeoKeywordWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordWorkspace::class, 'workspace_id');
    }
}
