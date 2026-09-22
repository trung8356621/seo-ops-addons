<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Support\AiExecutionTransport;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptRoutingPolicyResolver;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\Seeding\System\SeedingCommentGenerateCapabilityHandler;
use PHPUnit\Framework\TestCase;

/**
 * Seeding Gen Comment: interactive + quick_free via shared AI stack (no provider HTTP in Seeding).
 */
final class SeedingInteractiveRoutingContractTest extends TestCase
{
    public function test_hook_defaults_text_fast_and_quick_free(): void
    {
        $profile = (new PromptExecutionProfileResolver())->hookDefault('seeding.comment.generate');
        $policy = (new PromptRoutingPolicyResolver())->hookDefault('seeding.comment.generate');

        self::assertSame(AiExecutionProfile::TextFast, $profile);
        self::assertSame(AiRoutingPolicy::QuickFree, $policy);
    }

    public function test_capability_handler_uses_interactive_transport_and_shared_port(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/System/SeedingCommentGenerateCapabilityHandler.php',
        );

        self::assertStringContainsString('AiTextExecutionPort', $src);
        self::assertStringContainsString('AiExecutionTransport::Interactive', $src);
        self::assertStringContainsString('PromptRoutingPolicyResolver', $src);
        self::assertStringContainsString('PromptExecutionProfileResolver', $src);
        self::assertStringContainsString('idempotency_key', $src);
        self::assertStringNotContainsString('OpenRouter', $src);
        self::assertStringNotContainsString('Http::', $src);
        self::assertStringNotContainsString('AiCandidatePlanner', $src);
        self::assertStringNotContainsString('::dispatch', $src);
    }

    public function test_seeding_service_does_not_call_providers(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/SeedingCommentGenerateService.php',
        );
        self::assertStringContainsString('SystemAiClient', $src);
        self::assertStringNotContainsString('OpenRouter', $src);
        self::assertStringNotContainsString('Http::post', $src);
        self::assertStringNotContainsString('Gemini', $src);
        self::assertStringNotContainsString('DeepSeek', $src);
    }

    public function test_share_draft_waits_for_server_success(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/seeding/SeedingWorkspace.jsx',
        );
        self::assertStringContainsString('Keep local draft until server confirms success', $src);
        self::assertStringContainsString('idempotency_key', $src);
        // Remove draft only after successful shareTopicApi await.
        $sharePos = strpos($src, 'const shareDraft = async');
        self::assertNotFalse($sharePos);
        $slice = substr($src, $sharePos, 1200);
        $awaitPos = strpos($slice, 'await shareTopicApi');
        $removePos = strpos($slice, 'removeDraftTopic');
        self::assertNotFalse($awaitPos);
        self::assertNotFalse($removePos);
        self::assertGreaterThan($awaitPos, $removePos);
    }

    public function test_capability_key_constant(): void
    {
        self::assertSame('seeding.comment.generate', SeedingCommentGenerateCapabilityHandler::KEY);
        self::assertSame('interactive', AiExecutionTransport::Interactive->value);
        self::assertSame('quick_free', AiRoutingPolicy::QuickFree->value);
    }
}
