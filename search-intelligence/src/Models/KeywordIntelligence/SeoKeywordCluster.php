<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterType;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordFunnelStage;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoKeywordCluster extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_clusters';

    protected $guarded = [];

    protected $casts = [
        'workspace_id' => 'integer',
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'topic_id' => 'integer',
        'primary_keyword_id' => 'integer',
        'cluster_type' => KeywordClusterType::class,
        'status' => KeywordClusterStatus::class,
        'search_intent' => KeywordSearchIntent::class,
        'funnel_stage' => KeywordFunnelStage::class,
        'total_search_volume' => 'integer',
        'avg_keyword_difficulty' => 'decimal:2',
        'relevance_score' => 'decimal:2',
        'opportunity_score' => 'decimal:2',
        'priority_score' => 'decimal:2',
        'keyword_count' => 'integer',
        'preserve_manual_primary' => 'boolean',
        'converted_at' => 'datetime',
        'metadata' => 'array',
    ];

    /** @return BelongsTo<SeoKeywordWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordWorkspace::class, 'workspace_id');
    }

    /** @return BelongsTo<SeoKiTopic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(SeoKiTopic::class, 'topic_id');
    }

    /** @return BelongsTo<SeoKiKeyword, $this> */
    public function primaryKeyword(): BelongsTo
    {
        return $this->belongsTo(SeoKiKeyword::class, 'primary_keyword_id');
    }

    /** @return HasMany<SeoKiKeyword, $this> */
    public function keywords(): HasMany
    {
        return $this->hasMany(SeoKiKeyword::class, 'cluster_id');
    }

    /** @return HasMany<SeoTopicClusterLink, $this> */
    public function topicLinks(): HasMany
    {
        return $this->hasMany(SeoTopicClusterLink::class, 'cluster_id');
    }
}
