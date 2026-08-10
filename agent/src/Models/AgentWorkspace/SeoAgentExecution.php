<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoAgentExecution extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_executions';

    protected $guarded = [];

    protected $casts = [
        'conversation_id' => 'integer',
        'message_id' => 'integer',
        'parent_execution_id' => 'integer',
        'plan_id' => 'integer',
        'step_index' => 'integer',
        'attempt' => 'integer',
        'confirmed_by' => 'integer',
        'input_summary' => 'array',
        'input_payload' => 'array',
        'preview_payload' => 'array',
        'result_summary' => 'array',
        'result_payload' => 'array',
        'error_payload' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'confirmation_expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SeoAgentConversation::class, 'conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SeoAgentMessage::class, 'message_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_execution_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_execution_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SeoAgentExecutionPlan::class, 'plan_id');
    }
}
