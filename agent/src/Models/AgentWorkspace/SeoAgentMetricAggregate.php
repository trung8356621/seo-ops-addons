<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;

class SeoAgentMetricAggregate extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_metric_aggregates';

    protected $guarded = [];

    protected $casts = [
        'bucket_date' => 'date',
        'site_id' => 'integer',
        'dimensions' => 'array',
        'value_sum' => 'float',
        'value_count' => 'integer',
    ];
}
