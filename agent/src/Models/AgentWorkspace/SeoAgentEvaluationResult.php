<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;

class SeoAgentEvaluationResult extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_evaluation_results';

    protected $guarded = [];

    protected $casts = [
        'run_id' => 'integer',
        'case_id' => 'integer',
        'score' => 'float',
        'scores' => 'array',
        'observed' => 'array',
        'violations' => 'array',
        'latency_ms' => 'integer',
        'token_usage' => 'array',
    ];
}
