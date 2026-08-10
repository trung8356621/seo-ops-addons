<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordTopicStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordTopicType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bảng `seo_topics` (Keyword Intelligence). `parent_id` tự tham chiếu, không dùng FK
 * constraint theo quy ước tránh cross-table constraint của addon.
 */
class SeoKiTopic extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_topics';

    protected $guarded = [];

    protected $casts = [
        'workspace_id' => 'integer',
        'parent_id' => 'integer',
        'topic_type' => KeywordTopicType::class,
        'status' => KeywordTopicStatus::class,
        'depth' => 'integer',
        'keyword_count' => 'integer',
        'cluster_count' => 'integer',
        'total_search_volume' => 'integer',
        'score' => 'decimal:2',
        'metadata' => 'array',
    ];

    /** @return BelongsTo<SeoKeywordWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordWorkspace::class, 'workspace_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<SeoKiKeyword, $this> */
    public function keywords(): HasMany
    {
        return $this->hasMany(SeoKiKeyword::class, 'topic_id');
    }

    /** @return HasMany<SeoKeywordCluster, $this> */
    public function clusters(): HasMany
    {
        return $this->hasMany(SeoKeywordCluster::class, 'topic_id');
    }

    /** @return HasMany<SeoTopicClusterLink, $this> */
    public function clusterLinks(): HasMany
    {
        return $this->hasMany(SeoTopicClusterLink::class, 'topic_id');
    }
}
