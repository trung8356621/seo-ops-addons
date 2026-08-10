<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoAgentAutomationApproval extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_automation_approvals';

    protected $guarded = [];

    protected $casts = [
        'automation_id' => 'integer',
        'run_id' => 'integer',
        'actor_user_id' => 'integer',
        'definition_version' => 'integer',
        'preview_payload' => 'array',
        'expires_at' => 'datetime',
        'resolved_at' => 'datetime',
        'resolved_by' => 'integer',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(SeoAgentAutomation::class, 'automation_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SeoAgentAutomationRun::class, 'run_id');
    }
}
