<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoAgentAutomationState extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_automation_states';

    protected $guarded = [];

    protected $casts = [
        'automation_id' => 'integer',
        'payload' => 'array',
        'observed_at' => 'datetime',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(SeoAgentAutomation::class, 'automation_id');
    }
}
