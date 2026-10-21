<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaBrowserSession extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_browser_sessions';

    protected $fillable = [
        'session_uuid', 'agent_id', 'platform', 'domain', 'ready_status',
        'last_confirmed_at', 'capabilities', 'locked_job_id', 'locked_at', 'lock_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'last_confirmed_at' => 'datetime',
            'locked_at' => 'datetime',
            'lock_expires_at' => 'datetime',
            'locked_job_id' => 'integer',
        ];
    }

    /** @return BelongsTo<SaAgent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(SaAgent::class, 'agent_id');
    }

    /** @return HasMany<SaSessionAccount, $this> */
    public function sessionAccounts(): HasMany
    {
        return $this->hasMany(SaSessionAccount::class, 'browser_session_id');
    }
}
