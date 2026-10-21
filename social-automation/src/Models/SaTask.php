<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaTask extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_tasks';

    protected $fillable = [
        'task_uuid', 'name', 'status', 'package_key', 'package_version', 'package_kind',
        'content_digest', 'inputs', 'target_account_count', 'eligibility', 'execution_policy',
    ];

    protected function casts(): array
    {
        return [
            'inputs' => 'array',
            'eligibility' => 'array',
            'execution_policy' => 'array',
            'target_account_count' => 'integer',
        ];
    }

    /** @return HasMany<SaJob, $this> */
    public function jobs(): HasMany
    {
        return $this->hasMany(SaJob::class, 'task_id');
    }

    /** @return HasMany<SaSchedule, $this> */
    public function schedules(): HasMany
    {
        return $this->hasMany(SaSchedule::class, 'task_id');
    }
}
