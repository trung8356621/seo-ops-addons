<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;

class SeoAgentTrace extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_traces';

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'actor_user_id' => 'integer',
        'references_json' => 'array',
        'version_snapshot' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
    ];
}
