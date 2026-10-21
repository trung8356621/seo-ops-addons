<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaScheduleTrigger extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_schedule_triggers';

    protected $fillable = [
        'trigger_uuid', 'schedule_id', 'task_id', 'idempotency_key', 'fired_at',
        'outcome', 'target_count', 'dispatched_count', 'shortfall', 'skip_reason', 'summary',
    ];

    protected function casts(): array
    {
        return [
            'fired_at' => 'datetime',
            'summary' => 'array',
            'target_count' => 'integer',
            'dispatched_count' => 'integer',
            'shortfall' => 'integer',
        ];
    }

    /** @return BelongsTo<SaSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(SaSchedule::class, 'schedule_id');
    }
}
