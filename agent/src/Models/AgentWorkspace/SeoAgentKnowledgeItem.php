<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SeoAgentKnowledgeItem extends Model
{
    use SoftDeletes;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_knowledge_items';

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'owner_user_id' => 'integer',
        'source_metadata' => 'array',
        'priority' => 'integer',
        'version' => 'integer',
        'supersedes_id' => 'integer',
        'created_by' => 'integer',
        'approved_by' => 'integer',
        'disabled_by' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'last_verified_at' => 'datetime',
        'approved_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(SeoAgentKnowledgeChunk::class, 'knowledge_item_id');
    }
}
