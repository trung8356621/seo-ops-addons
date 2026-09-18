<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit\System;

use PHPUnit\Framework\TestCase;

final class SystemLegacyAdapterContractTest extends TestCase
{
    public function test_legacy_ai_port_wraps_canonical_not_prompt_runner(): void
    {
        $path = dirname(__DIR__, 3).'/src/System/LegacyCanonicalAiTextExecutionPort.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('CanonicalAiTextExecutionService', $source);
        self::assertStringContainsString('AiTextExecutionPort', $source);
        self::assertStringNotContainsString('PromptRunnerService', $source);
        self::assertStringNotContainsString('Omnichannel\\Addons\\Seo\\', $source);
        self::assertStringNotContainsString('Omnichannel\\Addons\\ContentProjects\\', $source);
    }

    public function test_legacy_workflow_port_uses_seo_tasks_without_runner_side_effects(): void
    {
        $path = dirname(__DIR__, 3).'/src/System/LegacySeoTaskWorkflowRuntimePort.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('seo_tasks', $source);
        self::assertStringContainsString('WorkflowRuntimePort', $source);
        self::assertStringNotContainsString('use Omnichannel\\Addons\\AiPrompt\\Services\\TaskWorkflowTestRunner', $source);
        self::assertStringNotContainsString('CreateArticlesFromTaskService', $source);
        self::assertStringContainsString('execution', $source);
        self::assertStringContainsString('deferred', $source);
    }

    public function test_provider_registers_system_ports(): void
    {
        $path = dirname(__DIR__, 3).'/src/AiPromptServiceProvider.php';
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('AiTextExecutionPort', $source);
        self::assertStringContainsString('WorkflowRuntimePort', $source);
        self::assertStringContainsString('LegacyCanonicalAiTextExecutionPort', $source);
        self::assertStringContainsString('LegacySeoTaskWorkflowRuntimePort', $source);
    }
}
