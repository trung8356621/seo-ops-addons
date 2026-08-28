<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;

final class SeoMcpTopicGroup extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_mcp_topic_groups';

    protected $fillable = [
        'site_id',
        'group_ref',
        'mask_name',
        'mask_name_manual',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'mask_name_manual' => 'boolean',
    ];
}
