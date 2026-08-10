<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;

class ContentProjectAgentSession extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_project_agent_sessions';

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
