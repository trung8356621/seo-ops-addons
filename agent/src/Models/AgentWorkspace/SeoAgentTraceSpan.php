<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;

class SeoAgentTraceSpan extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_trace_spans';

    protected $guarded = [];

    protected $casts = [
        'attributes' => 'array',
        'references_json' => 'array',
        'duration_ms' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
