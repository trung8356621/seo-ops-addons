<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;

final class SeoTopicClusterMeta extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_topic_cluster_meta';

    protected $fillable = [
        'site_id',
        'cluster_key',
        'canonical_phrase',
        'normalized_canonical',
        'confidence',
        'needs_review',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'needs_review' => 'boolean',
    ];
}
