<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;

class ContentProjectAgentApproval extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_project_agent_approvals';

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'preview_payload' => 'array',
        'expires_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];
}
