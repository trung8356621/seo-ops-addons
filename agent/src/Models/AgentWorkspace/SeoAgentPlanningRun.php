<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoAgentPlanningRun extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_planning_runs';

    protected $guarded = [];

    protected $casts = [
        'conversation_id' => 'integer',
        'message_id' => 'integer',
        'input_token_estimate' => 'integer',
        'output_token_estimate' => 'integer',
        'confidence' => 'float',
        'adjusted_confidence' => 'float',
        'latency_ms' => 'integer',
        'context_manifest' => 'array',
        'structured_response' => 'array',
        'validation_errors' => 'array',
        'repair_actions' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SeoAgentConversation::class, 'conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SeoAgentMessage::class, 'message_id');
    }
}
