<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;

final class SeoMcpTopicGroupMember extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_mcp_topic_group_members';

    protected $fillable = [
        'site_id',
        'group_ref',
        'cluster_key',
    ];

    protected $casts = [
        'site_id' => 'integer',
    ];
}
