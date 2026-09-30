<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\IndustryContext\IndustryContextSchema;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultIndustryContextPromptInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\IndustryContextPromptCompiler;
use PHPUnit\Framework\TestCase;

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
}
