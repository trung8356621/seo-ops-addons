<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptPartsCompiler;
use Omnichannel\Addons\AiPrompt\Support\ProviderChatMessageBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Phase B: structured system/user → provider message layouts (lossless semantics).
 */
final class PromptCompilationPhaseBTest extends TestCase
{
    public function test_compiler_to_openai_messages_preserves_global_constraints(): void
    {
        $prompt = $this->prompt(<<<'MD'
# Ràng buộc tổng (Global)
Never invent product facts.

# Nhiệm vụ
Write one meta description for {{title}}.
MD);

        $compiled = (new SeoPromptPartsCompiler)->compile($prompt, ['title' => 'Canvas bag']);
        $messages = ProviderChatMessageBuilder::openAiCompatible(
            $compiled->systemText(),
            $compiled->userText(),
            true,
        );

        self::assertSame('system', $messages[0]['role']);
        self::assertStringContainsString('Never invent product facts.', $messages[0]['content']);
        self::assertSame('user', $messages[1]['role']);
        self::assertStringContainsString('Canvas bag', $messages[1]['content']);
        self::assertStringNotContainsString('Never invent product facts.', $messages[1]['content']);
    }

    public function test_unsupported_system_role_prepends_never_drops(): void
    {
        $prompt = $this->prompt(<<<'MD'
# Ràng buộc tổng (Global)
Keep claims verifiable.

# Nhiệm vụ
Suggest a title.
MD);

        $compiled = (new SeoPromptPartsCompiler)->compile($prompt, []);
        $messages = ProviderChatMessageBuilder::openAiCompatible(
            $compiled->systemText(),
            $compiled->userText(),
            false,
        );

        self::assertCount(1, $messages);
        self::assertSame('user', $messages[0]['role']);
        self::assertStringContainsString('Keep claims verifiable.', $messages[0]['content']);
        self::assertStringContainsString('Suggest a title.', $messages[0]['content']);
    }

    public function test_assembler_source_stamps_compiled_system_user_on_user_side_only_for_contracts(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/PromptHooks/PromptHookPromptAssembler.php',
        );
        self::assertStringContainsString('compileInstructions(', $src);
        self::assertStringContainsString("'_compiled_system'", $src);
        self::assertStringContainsString("'_compiled_user'", $src);
        self::assertStringContainsString('appendToPrompt($user', $src);
        self::assertStringNotContainsString('appendToPrompt($final', $src);
    }

    public function test_runner_wires_provider_chat_options(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/PromptRunnerService.php',
        );
        self::assertStringContainsString('withProviderChatOptions(', $src);
        self::assertStringContainsString('stampCompiledChatVariables(', $src);
        self::assertStringContainsString('compileChainStepInstructions(', $src);
    }

    public function test_openai_adapter_uses_message_builder(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ProviderTemplates/OpenAiCompatibleProtocolAdapter.php',
        );
        self::assertStringContainsString('ProviderChatMessageBuilder::openAiCompatible', $src);
        self::assertStringContainsString("options['system']", $src);
    }

    public function test_runner_syncs_compiled_stamps_before_provider_options(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/PromptRunnerService.php',
        );
        self::assertStringContainsString('CompiledChatVariableSync::align', $src);
        self::assertStringContainsString('withProviderChatOptions(', $src);
    }

    private function prompt(string $markdown): SeoPrompt
    {
        $prompt = new SeoPrompt;
        $prompt->forceFill([
            'id' => 1,
            'markdown_content' => $markdown,
            'is_active' => true,
        ]);

        return $prompt;
    }
}
