<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;

class SeoAgentMetricEvent extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_metric_events';

    protected $guarded = [];

    protected $casts = [
        'site_id' => 'integer',
        'actor_user_id' => 'integer',
        'dimensions' => 'array',
        'value' => 'float',
        'occurred_at' => 'datetime',
    ];
}
