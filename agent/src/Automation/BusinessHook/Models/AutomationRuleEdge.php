<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Models;

use App\Support\Automation\AutomationModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $automation_rule_id
 * @property string $from_node_key
 * @property string $to_node_key
 * @property string|null $branch
 * @property int $priority
 * @property array<string, mixed>|null $condition
 */
final class AutomationRuleEdge extends AutomationModel
{
    protected $table = 'automation_rule_edges';

    protected $guarded = [];

    protected $casts = [
        'automation_rule_id' => 'integer',
        'priority' => 'integer',
        'condition' => 'array',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }
}
