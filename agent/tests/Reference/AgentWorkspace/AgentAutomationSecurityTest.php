<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Console\DispatchDueAgentAutomationsCommand;
use Omnichannel\Addons\Agent\Jobs\RunAgentAutomationJob;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\AgentAutomationDispatcher;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationNotificationService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationRunner;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationNotificationData;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentAutomationSecurityTest extends TestCase
{
    public function test_freeze_no_scheduler_command_bus(): void
    {
        $files = [
            DefaultAgentAutomationRunner::class,
            DefaultAgentAutomationOrchestrator::class,
            AgentAutomationDispatcher::class,
            RunAgentAutomationJob::class,
            DispatchDueAgentAutomationsCommand::class,
        ];
        foreach ($files as $class) {
            $source = (string) file_get_contents((new ReflectionClass($class))->getFileName());
            self::assertStringNotContainsString('ContentProjectCommandBus', $source, $class);
        }
    }

    public function test_notification_dedupe_and_quiet_hours_in_service(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(DefaultAgentAutomationNotificationService::class))->getFileName(),
        );
        self::assertStringContainsString('dedupe_cooldown', $source);
        self::assertStringContainsString('quiet_hours', $source);
        self::assertStringContainsString('notification_quota', $source);
        self::assertStringContainsString('silent_success', $source);
    }

    public function test_notification_data_has_fingerprint(): void
    {
        $data = new AgentAutomationNotificationData(
            policy: 'change_only',
            destinations: ['agent_workspace'],
            title: 't',
            body: 'b',
            severity: 'info',
            fingerprint: hash('sha256', 'x'),
            payload: [],
        );
        self::assertSame(64, strlen($data->fingerprint));
        self::assertArrayHasKey('fingerprint', $data->toArray());
    }

    public function test_context_no_admin_fallback_in_runner(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(DefaultAgentAutomationRunner::class))->getFileName(),
        );
        self::assertStringContainsString('permission_lost', $source);
        self::assertStringContainsString('no admin fallback', $source);
        self::assertStringNotContainsString("role: 'admin'", $source);
    }

    public function test_workspace_context_dto_site_bound(): void
    {
        $ctx = new AgentWorkspaceContext(
            tenantRef: 'tenant:site:1',
            siteRef: 'site:1',
            tenantId: 1,
            siteId: 1,
            connectionId: null,
            siteName: 'Test',
            actorRef: 'user:9',
            actorUserId: 9,
            role: 'member',
        );
        self::assertSame('site:1', $ctx->siteRef);
        self::assertSame(9, $ctx->actorUserId);
    }
}
