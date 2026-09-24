<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\InteractivePromptExecutor;
use Omnichannel\Addons\AiPrompt\System\LegacyCanonicalAiTextExecutionPort;
use PHPUnit\Framework\TestCase;

/**
 * Architecture contracts for Interactive execution transport (no live provider).
 */
final class InteractivePromptExecutorContractTest extends TestCase
{
    public function test_interactive_executor_class_exists(): void
    {
        self::assertTrue(class_exists(InteractivePromptExecutor::class));
    }

    public function test_legacy_text_port_uses_interactive_executor(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/System/LegacyCanonicalAiTextExecutionPort.php',
        );
        self::assertStringContainsString('InteractivePromptExecutor', $src);
        self::assertStringContainsString('execution_transport', $src);
        self::assertStringContainsString('routing_policy', $src);
        self::assertStringNotContainsString('Http::post', $src);
        self::assertStringNotContainsString('dispatch(', $src);
    }

    public function test_interactive_executor_does_not_dispatch_jobs(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/InteractivePromptExecutor.php',
        );
        self::assertStringContainsString('AiExecutionTransport::Interactive', $src);
        self::assertStringNotContainsString('::dispatch', $src);
        self::assertStringNotContainsString('ShouldQueue', $src);
        self::assertStringContainsString('CanonicalAiTextExecutionService', $src);
        self::assertStringContainsString('AiRoutingOwnerResolver', $src);
        self::assertStringContainsString('resolveRoutingOwnerId', $src);
        self::assertStringNotContainsString('auth()->id()', $src);
    }

    public function test_prompt_execution_request_dto_exists(): void
    {
        self::assertTrue(class_exists(\Omnichannel\Addons\AiPrompt\DataTransfer\PromptExecutionRequest::class));
        self::assertTrue(enum_exists(\Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy::class));
        self::assertTrue(enum_exists(\Omnichannel\Addons\AiPrompt\Support\AiExecutionTransport::class));
    }
}
