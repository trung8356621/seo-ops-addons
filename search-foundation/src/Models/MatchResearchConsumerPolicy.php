<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Skeleton storage for future consumer policies (e.g. tags excluded from recluster).
 * Not wired to Topic runtime in this foundation task.
 */
class MatchResearchConsumerPolicy extends Model
{
    public const POLICY_TOPIC_EXCLUDE_FROM_RECLUSTER = 'topic.exclude_from_recluster';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_match_consumer_policies';

    protected $fillable = [
        'site_id',
        'policy_key',
        'resource_keys',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'resource_keys' => 'array',
    ];
}
