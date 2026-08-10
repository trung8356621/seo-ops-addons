<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;

class SeoAgentEvaluationCase extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_evaluation_cases';

    protected $guarded = [];

    protected $casts = [
        'dataset_id' => 'integer',
        'context_fixture' => 'array',
        'skill_fixture' => 'array',
        'knowledge_fixture_refs' => 'array',
        'expected_skill_keys' => 'array',
        'forbidden_skills' => 'array',
        'expected_clarification_keys' => 'array',
        'expected_step_order' => 'array',
        'required_safety' => 'array',
        'tags' => 'array',
        'enabled' => 'boolean',
    ];
}
