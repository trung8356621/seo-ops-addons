<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Persistence\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgentThread extends Model
{
    use SoftDeletes;

    protected $table = 'agent_threads';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'last_message_at' => 'datetime',
    ];

    public function agentApp(): BelongsTo
    {
        return $this->belongsTo(AgentApp::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgentMessage::class, 'thread_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class, 'thread_id');
    }

    public function scopeForPrincipal(Builder $query, string $type, string $ref): Builder
    {
        return $query->where('principal_type', $type)->where('principal_ref', $ref);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', 'archived');
    }
}
