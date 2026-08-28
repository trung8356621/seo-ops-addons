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
        'canonical_source',
        'mcp_excluded',
        'seo_excluded',
    ];

    public const SOURCE_AUTO = 'auto';

    public const SOURCE_MANUAL = 'manual';

    protected $casts = [
        'site_id' => 'integer',
        'needs_review' => 'boolean',
        'mcp_excluded' => 'boolean',
        'seo_excluded' => 'boolean',
    ];

    public function isManual(): bool
    {
        return (string) ($this->canonical_source ?? self::SOURCE_AUTO) === self::SOURCE_MANUAL;
    }
}
