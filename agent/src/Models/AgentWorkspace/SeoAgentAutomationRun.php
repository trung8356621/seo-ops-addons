<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoAgentAutomationRun extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_automation_runs';

    protected $guarded = [];

    protected $casts = [
        'automation_id' => 'integer',
        'attempt' => 'integer',
        'definition_version' => 'integer',
        'duration_ms' => 'integer',
        'step_results' => 'array',
        'condition_result' => 'array',
        'result_summary' => 'array',
        'error_payload' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(SeoAgentAutomation::class, 'automation_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(SeoAgentAutomationApproval::class, 'run_id');
    }
}
