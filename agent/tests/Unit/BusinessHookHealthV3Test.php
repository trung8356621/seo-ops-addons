<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationSchedulerHeartbeatService;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookSchemaGuard;
use Tests\TestCase;

final class BusinessHookHealthV3Test extends TestCase
{
    public function test_health_report_top_level_shape(): void
    {
        $keys = ['checked_at', 'scheduler', 'backlog', 'stale', 'dead_letters', 'healthy'];

        self::assertSame($keys, array_keys(array_fill_keys($keys, null)));
    }

    public function test_scheduler_heartbeat_names_are_stable(): void
    {
        self::assertSame('dispatch_scheduled', AutomationSchedulerHeartbeatService::NAME_DISPATCH_SCHEDULED);
        self::assertSame('recover_stale', AutomationSchedulerHeartbeatService::NAME_RECOVER_STALE);
    }

    public function test_schema_guard_v3_tables_include_heartbeats(): void
    {
        self::assertContains('automation_scheduler_heartbeats', BusinessHookSchemaGuard::V3_TABLES);
        self::assertContains('automation_rule_versions', BusinessHookSchemaGuard::V3_TABLES);
    }

    public function test_schema_guard_v3_hint_command(): void
    {
        self::assertStringContainsString('--only-v3', BusinessHookSchemaGuard::migrateV3Hint());
    }

    public function test_backlog_and_stale_sections_use_expected_metric_keys(): void
    {
        $backlog = [
            'pending_executions' => 0,
            'processing_executions' => 0,
            'pending_nodes' => 0,
        ];
        $stale = [
            'executions' => 0,
            'nodes' => 0,
        ];
        $deadLetters = [
            'failed_executions_7d' => 0,
        ];

        self::assertCount(3, $backlog);
        self::assertCount(2, $stale);
        self::assertArrayHasKey('failed_executions_7d', $deadLetters);
    }
}
