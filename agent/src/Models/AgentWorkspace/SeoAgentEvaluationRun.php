<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;

class SeoAgentEvaluationRun extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_evaluation_runs';

    protected $guarded = [];

    protected $casts = [
        'dataset_id' => 'integer',
        'config_snapshot' => 'array',
        'summary' => 'array',
        'created_by' => 'integer',
        'dry_run' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
