<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoAgentExecutionPlan extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_execution_plans';

    protected $guarded = [];

    protected $casts = [
        'conversation_id' => 'integer',
        'site_id' => 'integer',
        'created_by' => 'integer',
        'current_step_index' => 'integer',
        'steps' => 'array',
        'bindings' => 'array',
        'cancelled_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SeoAgentConversation::class, 'conversation_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(SeoAgentExecution::class, 'plan_id');
    }
}
