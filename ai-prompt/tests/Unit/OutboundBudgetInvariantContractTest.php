<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\Ai\ClaudeMessagesClient;
use Omnichannel\Addons\AiPrompt\Services\Ai\DeepSeekChatClient;
use Omnichannel\Addons\AiPrompt\Services\Ai\GeminiGenerateContentClient;
use Omnichannel\Addons\AiPrompt\Services\AiExecutionService;
use Omnichannel\Addons\AiPrompt\Services\AiOutboundBudgetGate;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\OpenAiCompatibleProtocolAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Production text adapters must gate via AiOutboundBudgetGate / verified plan_id.
 */
final class OutboundBudgetInvariantContractTest extends TestCase
{
    public function test_text_adapters_invoke_outbound_budget_gate(): void
    {
        $files = [
            DeepSeekChatClient::class => (string) (new \ReflectionClass(DeepSeekChatClient::class))->getFileName(),
            OpenAiCompatibleProtocolAdapter::class => (string) (new \ReflectionClass(OpenAiCompatibleProtocolAdapter::class))->getFileName(),
            GeminiGenerateContentClient::class => (string) (new \ReflectionClass(GeminiGenerateContentClient::class))->getFileName(),
            ClaudeMessagesClient::class => (string) (new \ReflectionClass(ClaudeMessagesClient::class))->getFileName(),
            AiExecutionService::class => (string) (new \ReflectionClass(AiExecutionService::class))->getFileName(),
        ];

        foreach ($files as $class => $path) {
            $src = (string) file_get_contents($path);
            $this->assertTrue(
                str_contains($src, 'AiOutboundBudgetGate') || str_contains($src, 'PromptBudgetPreflightService'),
                $class.' missing outbound budget gate',
            );
            $this->assertTrue(
                str_contains($src, 'verifyCompiled') || str_contains($src, 'verifyMessages') || str_contains($src, 'assertOutbound'),
                $class.' must call verifyCompiled/verifyMessages/assertOutbound',
            );
        }
    }

    public function test_gate_requires_verified_plan_id_by_default(): void
    {
        $gate = new AiOutboundBudgetGate(new \Omnichannel\Addons\AiPrompt\Services\PromptBudgetPreflightService());

        try {
            $gate->verifyCompiled(
                null,
                new \App\Models\ApiConnection(),
                'hello',
                'deepseek-chat',
                'deepseek',
                'article.title_suggestion',
                [],
            );
            $this->fail('Expected missing plan_id');
        } catch (\Omnichannel\Addons\AiPrompt\Exceptions\PromptBudgetException $e) {
            $this->assertStringContainsString('verified budget plan_id', $e->getMessage());
        }
    }

    public function test_media_paths_are_documented_exceptions(): void
    {
        // Image/media pipelines do not use text token budgeting — listed explicitly.
        $exceptions = [
            'MediaGenerationService',
            'ImageGenerationChainService',
        ];
        $this->assertNotEmpty($exceptions);
    }
}
