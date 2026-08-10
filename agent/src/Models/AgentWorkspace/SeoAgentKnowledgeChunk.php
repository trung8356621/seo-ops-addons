<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoAgentKnowledgeChunk extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_knowledge_chunks';

    protected $guarded = [];

    protected $casts = [
        'knowledge_item_id' => 'integer',
        'chunk_index' => 'integer',
        'token_estimate' => 'integer',
        'metadata' => 'array',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(SeoAgentKnowledgeItem::class, 'knowledge_item_id');
    }
}
