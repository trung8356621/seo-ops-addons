<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentExecutionContextUpdater;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\DefaultAgentExecutionOrchestrator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentExecutionContextTest extends TestCase
{
    public function test_allowlist_keys(): void
    {
        $allowed = AgentExecutionContextUpdater::ALLOWED_KEYS;
        self::assertSame([
            'project_ref',
            'workspace_ref',
            'article_ref',
            'selected_item_refs',
            'keyword_workspace_ref',
            'serp_workspace_ref',
            'last_execution_ref',
        ], $allowed);
    }

    public function test_orchestrator_uses_context_updater_and_redacts_secrets(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(DefaultAgentExecutionOrchestrator::class))->getFileName(),
        );

        self::assertStringContainsString('AgentExecutionContextUpdater', $source);
        self::assertStringContainsString('redactInput', $source);
        self::assertStringContainsString('[redacted]', $source);
        self::assertStringNotContainsString('confirmation_token_hash = $confirmationToken', $source);
    }

    public function test_migration_phase2_exists_and_is_additive(): void
    {
        $path = \Omnichannel\Addons\Seo\Support\SeoMigrationPath::find('2026_07_28_210000_phase2_agent_execution_orchestration.php');
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('confirmation_token_hash', $source);
        self::assertStringContainsString('idempotency_key', $source);
        self::assertStringContainsString('seo_agent_execution_plans', $source);
        self::assertStringContainsString('parent_execution_id', $source);
        self::assertStringNotContainsString('dropColumn(\'capability\')', $source);
    }
}
