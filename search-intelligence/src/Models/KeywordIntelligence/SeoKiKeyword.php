<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordAnalysisStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordFunnelStage;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordReviewStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bảng `seo_keywords` (Keyword Intelligence) — không nhầm với model `SeoKeyword` (bảng `keywords` cũ).
 */
class SeoKiKeyword extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keywords';

    protected $guarded = [];

    protected $casts = [
        'workspace_id' => 'integer',
        'source' => KeywordSource::class,
        'search_volume' => 'integer',
        'keyword_difficulty' => 'decimal:2',
        'cpc' => 'decimal:2',
        'competition' => 'decimal:2',
        'search_intent' => KeywordSearchIntent::class,
        'funnel_stage' => KeywordFunnelStage::class,
        'analysis_status' => KeywordAnalysisStatus::class,
        'review_status' => KeywordReviewStatus::class,
        'relevance_score' => 'decimal:2',
        'business_value_score' => 'decimal:2',
        'opportunity_score' => 'decimal:2',
        'intent_score' => 'decimal:2',
        'total_score' => 'decimal:2',
        'priority_score' => 'decimal:2',
        'is_duplicate' => 'boolean',
        'is_primary' => 'boolean',
        'is_excluded' => 'boolean',
        'duplicate_of_keyword_id' => 'integer',
        'cluster_id' => 'integer',
        'topic_id' => 'integer',
        'secondary_intents' => 'array',
        'serp_features' => 'array',
        'metadata' => 'array',
        'field_sources' => 'array',
        'imported_by' => 'integer',
        'analyzed_at' => 'datetime',
        'tenant_id' => 'integer',
        'site_id' => 'integer',
    ];

    /** @return BelongsTo<SeoKeywordWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordWorkspace::class, 'workspace_id');
    }

    /** @return BelongsTo<SeoKeywordCluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordCluster::class, 'cluster_id');
    }

    /** @return BelongsTo<SeoKiTopic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(SeoKiTopic::class, 'topic_id');
    }

    /** @return BelongsTo<self, $this> */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_keyword_id');
    }

    /** @return HasMany<SeoKeywordArticleMapping, $this> */
    public function articleMappings(): HasMany
    {
        return $this->hasMany(SeoKeywordArticleMapping::class, 'keyword_id');
    }

    /** @return HasMany<SeoKeywordRelationship, $this> */
    public function relationships(): HasMany
    {
        return $this->hasMany(SeoKeywordRelationship::class, 'keyword_id');
    }
}
