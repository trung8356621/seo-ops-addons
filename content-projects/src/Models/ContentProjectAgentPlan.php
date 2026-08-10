<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentProjectAgentPlan extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_project_agent_plans';

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'current_step_index' => 'integer',
        'total_steps' => 'integer',
        'input_payload' => 'array',
        'resolved_context' => 'array',
        'summary' => 'array',
        'requires_user_confirmation' => 'boolean',
        'plan_version' => 'integer',
        'replan_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** @return HasMany<ContentProjectAgentPlanStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ContentProjectAgentPlanStep::class, 'plan_id')->orderBy('position');
    }
}
