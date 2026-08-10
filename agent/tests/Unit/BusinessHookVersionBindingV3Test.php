<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationRuleVersionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationGraphSnapshot;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleVersion;
use Tests\TestCase;

final class BusinessHookVersionBindingV3Test extends TestCase
{
    public function test_snapshot_prefers_version_nodes_over_live_draft(): void
    {
        $rule = new AutomationRule(['id' => 1, 'code' => 'r1', 'workflow_mode' => 'graph']);
        $rule->setRelation('nodes', collect([(object) ['node_key' => 'draft_only', 'node_type' => 'trigger', 'is_enabled' => true]]));
        $rule->setRelation('edges', collect());

        $version = new AutomationRuleVersion([
            'id' => 9,
            'automation_rule_id' => 1,
            'version' => 2,
            'status' => AutomationRuleVersionStatus::Published->value,
        ]);
        $version->setRelation('nodes', collect([(object) ['node_key' => 'trigger', 'node_type' => 'trigger', 'is_enabled' => true]]));
        $version->setRelation('edges', collect());

        $snapshot = AutomationGraphSnapshot::fromVersion($rule, $version);
        self::assertSame('trigger', $snapshot->nodes->first()->node_key);
        self::assertSame(9, $snapshot->version?->id);
    }

    public function test_version_status_enum_values(): void
    {
        self::assertSame('draft', AutomationRuleVersionStatus::Draft->value);
        self::assertSame('published', AutomationRuleVersionStatus::Published->value);
        self::assertSame('archived', AutomationRuleVersionStatus::Archived->value);
    }
}
