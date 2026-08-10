<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Models;

use App\Support\Automation\AutomationModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AutomationRuleVersionEdge extends AutomationModel
{
    protected $table = 'automation_rule_version_edges';

    protected $guarded = [];

    protected $casts = [
        'automation_rule_version_id' => 'integer',
        'priority' => 'integer',
        'condition' => 'array',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationRuleVersion::class, 'automation_rule_version_id');
    }
}
