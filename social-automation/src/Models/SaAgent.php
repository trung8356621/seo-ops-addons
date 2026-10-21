<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

/** @property string $agent_uuid */
final class SaAgent extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_agents';

    protected $fillable = [
        'agent_uuid', 'device_id', 'display_name', 'app_version', 'runtime_version',
        'capabilities', 'availability', 'max_concurrency', 'active_jobs',
        'last_heartbeat_at', 'last_error_code', 'last_error_message', 'credential_id',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'max_concurrency' => 'integer',
            'active_jobs' => 'integer',
            'last_heartbeat_at' => 'datetime',
            'credential_id' => 'integer',
        ];
    }

    /** @return HasMany<SaBrowserSession, $this> */
    public function browserSessions(): HasMany
    {
        return $this->hasMany(SaBrowserSession::class, 'agent_id');
    }
}
