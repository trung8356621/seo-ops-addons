<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SeoAgentAutomation extends Model
{
    use SoftDeletes;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_automations';

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'owner_user_id' => 'integer',
        'enabled' => 'boolean',
        'version' => 'integer',
        'trigger_json' => 'array',
        'workflow_json' => 'array',
        'condition_json' => 'array',
        'notification_json' => 'array',
        'policy_json' => 'array',
        'conversation_id' => 'integer',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'paused_at' => 'datetime',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(SeoAgentAutomationRun::class, 'automation_id');
    }

    public function states(): HasMany
    {
        return $this->hasMany(SeoAgentAutomationState::class, 'automation_id');
    }
}
