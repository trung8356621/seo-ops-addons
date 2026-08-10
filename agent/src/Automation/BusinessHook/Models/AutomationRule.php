<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Models;

use App\Support\Automation\AutomationModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $code
 * @property string $classification
 * @property string $visibility
 * @property string $name
 * @property string|null $description
 * @property string $event_name
 * @property bool $is_enabled
 * @property int $priority
 * @property bool $stop_on_failure
 * @property string $run_mode
 * @property string $workflow_mode
 * @property string $trigger_type
 * @property string|null $schedule_expression
 * @property string|null $schedule_timezone
 * @property \Illuminate\Support\Carbon|null $next_run_at
 * @property \Illuminate\Support\Carbon|null $last_scheduled_at
 * @property int $version
 * @property array<string, mixed>|null $conditions
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $locale_settings
 */
final class AutomationRule extends AutomationModel
{
    protected $table = 'automation_rules';

    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
        'allow_manual_trigger' => 'boolean',
        'priority' => 'integer',
        'stop_on_failure' => 'boolean',
        'version' => 'integer',
        'conditions' => 'array',
        'settings' => 'array',
        'locale_settings' => 'array',
        'next_run_at' => 'datetime',
        'last_scheduled_at' => 'datetime',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'site_id' => 'integer',
        'draft_revision' => 'integer',
        'published_version_id' => 'integer',
        'draft_version_id' => 'integer',
    ];

    public function actions(): HasMany
    {
        return $this->hasMany(AutomationRuleAction::class, 'automation_rule_id')
            ->orderBy('position');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AutomationRuleVersion::class, 'automation_rule_id')
            ->orderByDesc('version');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(AutomationRuleVersion::class, 'published_version_id');
    }

    public function draftVersion(): BelongsTo
    {
        return $this->belongsTo(AutomationRuleVersion::class, 'draft_version_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class, 'automation_rule_id');
    }

    public function latestExecution(): HasOne
    {
        return $this->hasOne(AutomationExecution::class, 'automation_rule_id')->latestOfMany();
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(AutomationRuleNode::class, 'automation_rule_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    public function edges(): HasMany
    {
        return $this->hasMany(AutomationRuleEdge::class, 'automation_rule_id')
            ->orderBy('priority')
            ->orderBy('id');
    }

    public function isGraphMode(): bool
    {
        return (string) ($this->workflow_mode ?? 'linear') === 'graph';
    }

    public function isLinearMode(): bool
    {
        return ! $this->isGraphMode();
    }
}
