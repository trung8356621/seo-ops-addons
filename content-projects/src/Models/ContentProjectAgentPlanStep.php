<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentProjectAgentPlanStep extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_project_agent_plan_steps';

    protected $guarded = [];

    protected $casts = [
        'plan_id' => 'integer',
        'position' => 'integer',
        'input_payload' => 'array',
        'resolved_input' => 'array',
        'attempt_count' => 'integer',
        'max_attempts' => 'integer',
        'depends_on_step_refs' => 'array',
        'condition_payload' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /** @return BelongsTo<ContentProjectAgentPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ContentProjectAgentPlan::class, 'plan_id');
    }
}
