<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentMessage extends Model
{
    protected $table = 'agent_messages';

    protected $guarded = [];

    protected $casts = [
        'response_payload' => 'array',
        'metadata' => 'array',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AgentThread::class, 'thread_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }
}
