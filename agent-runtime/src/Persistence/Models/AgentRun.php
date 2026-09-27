<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRun extends Model
{
    protected $table = 'agent_runs';

    protected $guarded = [];

    protected $casts = [
        'retrieval_summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AgentThread::class, 'thread_id');
    }

    public function userMessage(): BelongsTo
    {
        return $this->belongsTo(AgentMessage::class, 'user_message_id');
    }

    public function assistantMessage(): BelongsTo
    {
        return $this->belongsTo(AgentMessage::class, 'assistant_message_id');
    }
}
