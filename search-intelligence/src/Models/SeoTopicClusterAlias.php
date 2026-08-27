<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;

final class SeoTopicClusterAlias extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_topic_cluster_aliases';

    protected $fillable = [
        'site_id',
        'cluster_key',
        'alias_phrase',
        'normalized_alias',
    ];

    protected $casts = [
        'site_id' => 'integer',
    ];
}
