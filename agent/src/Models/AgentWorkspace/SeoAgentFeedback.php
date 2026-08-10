<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;

class SeoAgentFeedback extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_feedback';

    protected $guarded = [];

    protected $casts = [
        'conversation_id' => 'integer',
        'message_id' => 'integer',
        'actor_user_id' => 'integer',
        'site_id' => 'integer',
        'useful' => 'boolean',
    ];
}
