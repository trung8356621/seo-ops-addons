<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Models;

use App\Support\Automation\AutomationModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $automation_execution_id
 * @property int|null $automation_rule_node_id
 * @property string $node_key
 * @property string $node_type
 * @property string $status
 * @property int $attempt
 * @property string $idempotency_key
 * @property \Illuminate\Support\Carbon|null $available_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property \Illuminate\Support\Carbon|null $heartbeat_at
 * @property array<string, mixed>|null $input_snapshot
 * @property array<string, mixed>|null $output_snapshot
 * @property string|null $selected_branch
 * @property string|null $error_code
 * @property string|null $error_message
 */
final class AutomationNodeExecution extends AutomationModel
{
    protected $table = 'automation_node_executions';

    protected $guarded = [];

    protected $casts = [
        'automation_execution_id' => 'integer',
        'automation_rule_node_id' => 'integer',
        'attempt' => 'integer',
        'input_snapshot' => 'array',
        'output_snapshot' => 'array',
        'available_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'heartbeat_at' => 'datetime',
    ];

    public function execution(): BelongsTo
    {
        return $this->belongsTo(AutomationExecution::class, 'automation_execution_id');
    }

    public function ruleNode(): BelongsTo
    {
        return $this->belongsTo(AutomationRuleNode::class, 'automation_rule_node_id');
    }
}
