<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;

class SeoAgentPackTemplate extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_pack_templates';

    protected $guarded = [];

    protected $casts = [
        'pack_id' => 'integer',
        'revision_id' => 'integer',
        'definition_json' => 'array',
    ];
}
