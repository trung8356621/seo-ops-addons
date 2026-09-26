<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\AgentAutomationApprovalTokenService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentAutomationApprovalTest extends TestCase
{
    public function test_approval_token_hashed_not_raw_stored_in_service(): void
    {
        $svc = new AgentAutomationApprovalTokenService;
        $issued = $svc->issue([
            'actor_id' => 1,
            'automation_id' => 2,
            'run_id' => 3,
            'site_ref' => 'site:1',
        ]);
        self::assertStringStartsWith('awautoapr_', $issued['token']);
        self::assertSame(64, strlen($issued['hash']));
        self::assertSame($svc->hashToken($issued['token']), $issued['hash']);

        $payload = $svc->consume($issued['token']);
        self::assertIsArray($payload);
        self::assertNull($svc->consume($issued['token']));
    }

    public function test_orchestrator_approval_guards(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(DefaultAgentAutomationOrchestrator::class))->getFileName(),
        );
        self::assertStringContainsString('ai_cannot_approve', $source);
        self::assertStringContainsString('actor_mismatch', $source);
        self::assertStringContainsString('site_mismatch', $source);
        self::assertStringContainsString('stale_definition', $source);
        self::assertStringContainsString('expired_token', $source);
        self::assertStringContainsString('requires_phase2_confirm', $source);
    }

    public function test_runner_write_waits_approval(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(DefaultAgentAutomationRunner::class))->getFileName(),
        );
        self::assertStringContainsString('waiting_for_approval', $source);
        self::assertStringContainsString('execution_preview', $source);
        self::assertStringContainsString('createApproval', $source);
        self::assertStringNotContainsString('auto_confirm', $source);
    }
}
