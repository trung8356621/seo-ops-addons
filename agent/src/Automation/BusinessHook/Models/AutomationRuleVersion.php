<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Models;

use App\Support\Automation\AutomationModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $automation_rule_id
 * @property int $version
 * @property string $status
 * @property string $workflow_mode
 * @property string $trigger_type
 * @property string|null $event_name
 */
final class AutomationRuleVersion extends AutomationModel
{
    protected $table = 'automation_rule_versions';

    protected $guarded = [];

    protected $casts = [
        'automation_rule_id' => 'integer',
        'version' => 'integer',
        'conditions' => 'array',
        'settings' => 'array',
        'layout' => 'array',
        'draft_revision' => 'integer',
        'published_at' => 'datetime',
        'published_by' => 'integer',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(AutomationRuleVersionNode::class, 'automation_rule_version_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    public function edges(): HasMany
    {
        return $this->hasMany(AutomationRuleVersionEdge::class, 'automation_rule_version_id')
            ->orderBy('priority')
            ->orderBy('id');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
