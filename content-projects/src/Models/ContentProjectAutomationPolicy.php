<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;

class ContentProjectAutomationPolicy extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_project_automation_policies';

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'enabled' => 'boolean',
        'allowed_capabilities' => 'array',
        'blocked_capabilities' => 'array',
        'auto_generate' => 'boolean',
        'auto_review' => 'boolean',
        'auto_approve' => 'boolean',
        'auto_schedule' => 'boolean',
        'auto_publish' => 'boolean',
        'require_confirmation_for' => 'array',
        'max_items_per_plan' => 'integer',
        'max_plans_per_day' => 'integer',
        'allowed_publish_windows' => 'array',
        'auto_retry_transient' => 'boolean',
        'auto_retry_max' => 'integer',
        'pause_on_failure' => 'boolean',
        'pause_on_approval_reject' => 'boolean',
        'daily_action_budget' => 'integer',
        'daily_item_budget' => 'integer',
        'daily_cost_budget_cents' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];
}
