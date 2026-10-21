<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaSchedule extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_schedules';

    protected $fillable = [
        'schedule_uuid', 'task_id', 'cron_expression', 'timezone', 'enabled',
        'target_account_count', 'execution_policy', 'input_source', 'package_version_pin', 'last_fired_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'execution_policy' => 'array',
            'input_source' => 'array',
            'target_account_count' => 'integer',
            'last_fired_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SaTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(SaTask::class, 'task_id');
    }

    /** @return HasMany<SaScheduleTrigger, $this> */
    public function triggers(): HasMany
    {
        return $this->hasMany(SaScheduleTrigger::class, 'schedule_id');
    }
}
