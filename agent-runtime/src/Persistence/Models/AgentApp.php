<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentApp extends Model
{
    protected $table = 'agent_apps';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function threads(): HasMany
    {
        return $this->hasMany(AgentThread::class);
    }

    public static function findByKey(string $appKey): ?self
    {
        return self::where('app_key', $appKey)->first();
    }
}
