<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;

class SeoAgentReview extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_reviews';

    protected $guarded = [];

    protected $casts = [
        'site_id' => 'integer',
        'payload' => 'array',
        'assigned_to' => 'integer',
        'created_by' => 'integer',
        'resolved_at' => 'datetime',
    ];
}
