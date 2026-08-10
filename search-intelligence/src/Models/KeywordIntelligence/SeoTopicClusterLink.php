<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordTopicClusterRelationship;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoTopicClusterLink extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_topic_cluster_links';

    protected $guarded = [];

    protected $casts = [
        'topic_id' => 'integer',
        'cluster_id' => 'integer',
        'relationship' => KeywordTopicClusterRelationship::class,
        'position' => 'integer',
    ];

    /** @return BelongsTo<SeoKiTopic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(SeoKiTopic::class, 'topic_id');
    }

    /** @return BelongsTo<SeoKeywordCluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordCluster::class, 'cluster_id');
    }
}
