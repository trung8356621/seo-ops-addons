<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\IndustryContext\IndustryContextSchema;
use InvalidArgumentException;
use Mockery;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBindingRunner;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultIndustryContextPromptInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\IndustryContextGenerationService;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\IndustryContextPromptCompiler;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Tests\TestCase;
use UnexpectedValueException;

final class IndustryContextPromptTest extends TestCase
{
    public function test_definition_is_structured_and_loads(): void
    {
        $loader = new PromptHookDefinitionLoader(PromptHookDefinitionLoader::defaultV01Directory(), PromptHookDefinitionLoader::defaultPhase1Directory());
        $definition = $loader->indexed()['industry.context.generate@0.1.0'];
        self::assertSame('json', $definition->outputSchema->type);
        self::assertTrue($definition->model->structuredOutput);
    }

    public function test_editable_prompt_is_one_sentence_and_runtime_appends_schema(): void
    {
        $prompt = DefaultIndustryContextPromptInstaller::canonicalDefaultMarkdown();
        self::assertSame(1, preg_match_all('/[.!?](?:\s|$)/', $prompt));
        self::assertLessThan(500, strlen($prompt));
        self::assertStringNotContainsString('content_universe', $prompt);
        $compiled = IndustryContextPromptCompiler::compile($prompt);
        self::assertStringContainsString($prompt, $compiled);
        self::assertStringContainsString(IndustryContextSchema::json(), $compiled);
    }

    public function test_installer_contract_is_idempotent_and_preserves_operator_edits_by_default(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/Services/PromptOwnership/DefaultIndustryContextPromptInstaller.php');
        self::assertStringContainsString("where('hook_key', self::HOOK_KEY)", (string) $source);
        self::assertStringContainsString('elseif ($restoreCanonical)', (string) $source);
        self::assertStringContainsString('if (! isset($bindings[self::HOOK_KEY]))', (string) $source);
    }

    public function test_compile_prompt_uses_current_stored_prompt_without_executing_provider(): void
    {
        $runner = new class implements PromptHookBindingRunner
        {
            public bool $called = false;

            public function execute(SeoPrompt $prompt, array $variables = [], array $contextExtras = [], array $previousOutputs = []): array
            {
                $this->called = true;

                return [];
            }
        };
        $storedPrompt = new SeoPrompt;
        $storedPrompt->forceFill([
            'name' => DefaultIndustryContextPromptInstaller::PROMPT_NAME,
            'hook_key' => DefaultIndustryContextPromptInstaller::HOOK_KEY,
            'markdown_content' => 'OPERATOR EDITED {{context_name}} / {{language}} / {{market}}',
        ]);
        $promptRunner = Mockery::mock(PromptRunnerService::class);
        $promptRunner->shouldReceive('compilePrompt')
            ->once()
            ->with($storedPrompt, Mockery::on(fn (array $variables): bool => $variables === [
                'context_name' => 'Balo & túi xách',
                'language' => 'vi',
                'market' => 'VN',
                'notes' => 'Focus on retail.',
            ]))
            ->andReturn('OPERATOR EDITED Balo & túi xách / vi / VN');

        $service = new IndustryContextGenerationService($runner, $promptRunner, fn (): SeoPrompt => $storedPrompt);
        $compiled = $service->compilePrompt(' Balo & túi xách ', ' vi ', ' VN ', ' Focus on retail. ');

        self::assertFalse($runner->called);
        self::assertStringContainsString('OPERATOR EDITED Balo & túi xách / vi / VN', $compiled);
        self::assertStringNotContainsString(DefaultIndustryContextPromptInstaller::canonicalDefaultMarkdown(), $compiled);
        self::assertStringContainsString("Temporary generation notes:\nFocus on retail.", $compiled);
        self::assertStringContainsString(IndustryContextSchema::json(), $compiled);
        self::assertStringNotContainsString('CURRENT_CONTEXT_SENTINEL', $compiled);
        self::assertStringContainsString('human-readable values must primarily be Vietnamese', $compiled);
        self::assertStringContainsString('MOQ (số lượng đặt hàng tối thiểu)', $compiled);
        self::assertStringContainsString('OEM/ODM (sản xuất theo thiết kế hoặc thương hiệu đặt hàng)', $compiled);
        self::assertStringContainsString('EDC (các vật dụng thường mang theo hằng ngày)', $compiled);
        self::assertStringContainsString('Do not write complete names, descriptions, sentences, or headings in English', $compiled);
        self::assertStringContainsString('Other languages retain normal locale-aware behavior', $compiled);
    }

    public function test_compile_prompt_requires_name_and_never_calls_runner(): void
    {
        $runner = new class implements PromptHookBindingRunner
        {
            public bool $called = false;

            public function execute(SeoPrompt $prompt, array $variables = [], array $contextExtras = [], array $previousOutputs = []): array
            {
                $this->called = true;

                return [];
            }
        };
        $service = new IndustryContextGenerationService($runner);

        try {
            $service->compilePrompt('   ');
            self::fail('Blank name should be rejected.');
        } catch (InvalidArgumentException) {
            self::assertFalse($runner->called);
        }
    }

    public function test_generation_output_validation_accepts_valid_json_and_rejects_invalid_json(): void
    {
        $runner = new class implements PromptHookBindingRunner
        {
            public function execute(SeoPrompt $prompt, array $variables = [], array $contextExtras = [], array $previousOutputs = []): array
            {
                return [];
            }
        };
        $service = new IndustryContextGenerationService($runner);
        $context = array_fill_keys(IndustryContextSchema::TOP_LEVEL_KEYS, []);
        $context['schema_version'] = '1.0';
        foreach (array_diff(IndustryContextSchema::TOP_LEVEL_KEYS, ['schema_version', 'audiences', 'demand_drivers']) as $key) {
            $context[$key] = ['fixture' => null];
        }
        self::assertSame($context, $service->validatedOutput(json_encode($context, JSON_THROW_ON_ERROR)));

        $this->expectException(UnexpectedValueException::class);
        $service->validatedOutput('{"schema_version":"2.0"}');
    }
}
