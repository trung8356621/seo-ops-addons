<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Models;

use App\Support\Automation\AutomationModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $execution_uuid
 * @property int $business_event_id
 * @property int $automation_rule_id
 * @property int|null $automation_rule_version_id
 * @property int $rule_version
 * @property string $status
 * @property string $trigger_type
 * @property int|null $initiated_by_user_id
 * @property string|null $initiated_from
 * @property string|null $action_code
 * @property int $attempt
 * @property string $idempotency_key
 * @property array<string, mixed>|null $context
 * @property string|null $error_code
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $cancellation_requested_at
 * @property \Illuminate\Support\Carbon|null $heartbeat_at
 * @property string|null $scheduled_occurrence_key
 */
final class AutomationExecution extends AutomationModel
{
    protected $table = 'automation_executions';

    protected $guarded = [];

    protected $casts = [
        'business_event_id' => 'integer',
        'automation_rule_id' => 'integer',
        'automation_rule_version_id' => 'integer',
        'rule_version' => 'integer',
        'attempt' => 'integer',
        'initiated_by_user_id' => 'integer',
        'context' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cancellation_requested_at' => 'datetime',
        'heartbeat_at' => 'datetime',
    ];

    public function businessEvent(): BelongsTo
    {
        return $this->belongsTo(BusinessEvent::class, 'business_event_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(AutomationRuleVersion::class, 'automation_rule_version_id');
    }

    public function actionExecutions(): HasMany
    {
        return $this->hasMany(AutomationActionExecution::class, 'automation_execution_id')
            ->orderBy('position');
    }

    public function nodeExecutions(): HasMany
    {
        return $this->hasMany(AutomationNodeExecution::class, 'automation_execution_id')
            ->orderBy('id');
    }

    public function isCancellationRequested(): bool
    {
        return $this->cancellation_requested_at !== null;
    }
}
