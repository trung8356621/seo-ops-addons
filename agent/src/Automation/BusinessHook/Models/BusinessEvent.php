<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Models;

use App\Support\Automation\AutomationModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $event_uuid
 * @property string $event_name
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int|null $site_id
 * @property int|null $project_id
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $context
 * @property \Illuminate\Support\Carbon $occurred_at
 */
final class BusinessEvent extends AutomationModel
{
    public $timestamps = false;

    protected $table = 'business_events';

    protected $guarded = [];

    protected $casts = [
        'subject_id' => 'integer',
        'site_id' => 'integer',
        'project_id' => 'integer',
        'payload' => 'array',
        'context' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class, 'business_event_id');
    }
}
