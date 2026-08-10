<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Models;

use App\Support\Automation\AutomationModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $automation_execution_id
 * @property int|null $automation_rule_action_id
 * @property string $action_code
 * @property int $position
 * @property string $status
 * @property int $attempt
 * @property array<string, mixed>|null $input_snapshot
 * @property array<string, mixed>|null $output_snapshot
 * @property string|null $error_code
 * @property string|null $error_message
 */
final class AutomationActionExecution extends AutomationModel
{
    protected $table = 'automation_action_executions';

    protected $guarded = [];

    protected $casts = [
        'automation_execution_id' => 'integer',
        'automation_rule_action_id' => 'integer',
        'position' => 'integer',
        'attempt' => 'integer',
        'input_snapshot' => 'array',
        'output_snapshot' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function execution(): BelongsTo
    {
        return $this->belongsTo(AutomationExecution::class, 'automation_execution_id');
    }

    public function ruleAction(): BelongsTo
    {
        return $this->belongsTo(AutomationRuleAction::class, 'automation_rule_action_id');
    }
}
