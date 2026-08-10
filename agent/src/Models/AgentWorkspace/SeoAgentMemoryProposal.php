<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoAgentMemoryProposal extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_memory_proposals';

    protected $guarded = [];

    protected $casts = [
        'conversation_id' => 'integer',
        'message_id' => 'integer',
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'confidence' => 'float',
        'warnings' => 'array',
        'source_metadata' => 'array',
        'created_by' => 'integer',
        'resolved_by' => 'integer',
        'knowledge_item_id' => 'integer',
        'resolved_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SeoAgentConversation::class, 'conversation_id');
    }

    public function knowledgeItem(): BelongsTo
    {
        return $this->belongsTo(SeoAgentKnowledgeItem::class, 'knowledge_item_id');
    }
}
