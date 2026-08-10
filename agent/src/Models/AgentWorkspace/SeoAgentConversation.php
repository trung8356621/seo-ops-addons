<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoAgentConversation extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_conversations';

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'connection_id' => 'integer',
        'created_by' => 'integer',
        'is_pinned' => 'boolean',
        'context_summary' => 'array',
        'summary_version' => 'integer',
        'summary_until_message_id' => 'integer',
        'summary_updated_at' => 'datetime',
        'last_message_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function planningRuns(): HasMany
    {
        return $this->hasMany(SeoAgentPlanningRun::class, 'conversation_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SeoAgentMessage::class, 'conversation_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(SeoAgentExecution::class, 'conversation_id');
    }
}
