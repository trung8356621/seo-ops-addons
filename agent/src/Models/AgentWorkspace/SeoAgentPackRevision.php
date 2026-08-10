<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoAgentPackRevision extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_pack_revisions';

    protected $guarded = [];

    protected $casts = [
        'pack_id' => 'integer',
        'revision_no' => 'integer',
        'manifest_json' => 'array',
        'compiled_json' => 'array',
        'validation_report' => 'array',
        'gate_report' => 'array',
        'created_by' => 'integer',
        'activated_by' => 'integer',
        'activated_at' => 'datetime',
    ];

    public function pack(): BelongsTo
    {
        return $this->belongsTo(SeoAgentPack::class, 'pack_id');
    }

    public function skills(): HasMany
    {
        return $this->hasMany(SeoAgentPackSkill::class, 'revision_id');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(SeoAgentPackTemplate::class, 'revision_id');
    }
}
