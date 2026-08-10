<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Models;

use App\Support\Automation\AutomationModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $automation_rule_id
 * @property string $node_key
 * @property string $node_type
 * @property string|null $name
 * @property string|null $action_code
 * @property int|null $position
 * @property array<string, mixed>|null $config
 * @property array<string, mixed>|null $input_mapping
 * @property array<string, mixed>|null $settings
 * @property bool $is_enabled
 */
final class AutomationRuleNode extends AutomationModel
{
    protected $table = 'automation_rule_nodes';

    protected $guarded = [];

    protected $casts = [
        'automation_rule_id' => 'integer',
        'position' => 'integer',
        'config' => 'array',
        'input_mapping' => 'array',
        'settings' => 'array',
        'ui_position' => 'array',
        'is_enabled' => 'boolean',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(AutomationRuleEdge::class, 'automation_rule_id', 'automation_rule_id')
            ->where('from_node_key', $this->node_key)
            ->orderBy('priority');
    }
}
