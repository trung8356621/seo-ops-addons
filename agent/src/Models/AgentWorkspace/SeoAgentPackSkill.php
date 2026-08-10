<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;

class SeoAgentPackSkill extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_pack_skills';

    protected $guarded = [];

    protected $casts = [
        'pack_id' => 'integer',
        'revision_id' => 'integer',
        'definition_json' => 'array',
    ];
}
