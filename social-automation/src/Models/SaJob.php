<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaJob extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_jobs';

    protected $fillable = [
        'job_uuid', 'task_id', 'schedule_trigger_id', 'agent_id', 'browser_session_id', 'account_id',
        'package_key', 'package_version', 'content_digest', 'status', 'idempotency_key',
        'payload', 'result', 'error_code', 'error_message',
        'reserved_at', 'claimed_at', 'lease_expires_at', 'started_at', 'finished_at',
        'duration_ms', 'dispatch_source',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'reserved_at' => 'datetime',
            'claimed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<SaTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(SaTask::class, 'task_id');
    }

    /** @return HasMany<SaJobAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(SaJobAttempt::class, 'job_id');
    }

    /** @return HasMany<SaJobEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SaJobEvent::class, 'job_id');
    }
}
