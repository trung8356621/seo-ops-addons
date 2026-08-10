<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Models\AgentWorkspace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoAgentMessage extends Model
{
    public $timestamps = false;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_agent_messages';

    protected $guarded = [];

    protected $casts = [
        'conversation_id' => 'integer',
        'created_by' => 'integer',
        'structured_content' => 'array',
        'created_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SeoAgentConversation::class, 'conversation_id');
    }
}
