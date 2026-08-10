<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Models;

use App\Support\Automation\AutomationModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $automation_rule_id
 * @property string $action_code
 * @property int $position
 * @property bool $is_enabled
 * @property bool $continue_on_failure
 * @property int $delay_seconds
 * @property array<string, mixed>|null $input_mapping
 * @property array<string, mixed>|null $settings
 */
final class AutomationRuleAction extends AutomationModel
{
    protected $table = 'automation_rule_actions';

    protected $guarded = [];

    protected $casts = [
        'automation_rule_id' => 'integer',
        'position' => 'integer',
        'is_enabled' => 'boolean',
        'continue_on_failure' => 'boolean',
        'delay_seconds' => 'integer',
        'input_mapping' => 'array',
        'settings' => 'array',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }
}
