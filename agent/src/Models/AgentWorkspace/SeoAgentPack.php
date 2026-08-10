<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SeoAgentPack extends Model
{
    use SoftDeletes;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_packs';

    protected $guarded = [];

    protected $casts = [
        'metadata_json' => 'array',
        'active_revision_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    public function revisions(): HasMany
    {
        return $this->hasMany(SeoAgentPackRevision::class, 'pack_id');
    }
}
