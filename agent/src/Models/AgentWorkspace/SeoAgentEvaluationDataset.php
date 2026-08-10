<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoAgentEvaluationDataset extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_evaluation_datasets';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function cases(): HasMany
    {
        return $this->hasMany(SeoAgentEvaluationCase::class, 'dataset_id');
    }
}
