<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class FaqStructuredOutputRoutingContractTest extends TestCase
{
    public function test_legacy_faq_compiled_prompt_gets_json_contract(): void
    {
        $prepared = $this->prepareStructuredPrompt('Generate useful FAQs for this article.', [
            '_structured_output' => true,
            '_structured_strategy' => 'json_mode',
        ]);

        self::assertStringContainsString('Generate useful FAQs for this article.', $prepared);
        self::assertStringContainsString('JSON', $prepared);
        self::assertStringContainsString('"faqs"', $prepared);
        self::assertStringContainsString('"question"', $prepared);
        self::assertStringContainsString('"answer"', $prepared);
    }

    public function test_faq_json_contract_is_idempotent(): void
    {
        $variables = ['_structured_output' => true, '_structured_strategy' => 'json_mode'];
        $once = $this->prepareStructuredPrompt('Generate FAQs.', $variables);
        $twice = $this->prepareStructuredPrompt($once, $variables);

        self::assertSame($once, $twice);
        self::assertSame(1, substr_count($twice, 'FAQ_JSON_OUTPUT_CONTRACT_V1'));
    }

    public function test_existing_complete_faq_json_schema_is_not_duplicated(): void
    {
        $original = 'Return valid JSON: {"faqs":[{"question":"...","answer":"..."}]}';

        self::assertSame($original, $this->prepareStructuredPrompt($original, [
            '_structured_output' => true,
            '_structured_strategy' => 'json_mode',
        ]));
    }

    public function test_non_faq_non_structured_prompt_is_unchanged(): void
    {
        $original = "Write a normal text response.\nKeep this byte-equivalent.";
        self::assertSame($original, $this->prepareStructuredPrompt(
            $original,
            ['_structured_output' => false],
            'regular.text.prompt',
        ));
    }

    public function test_faq_provider_contract_has_prompt_and_json_mode_sides(): void
    {
        $variables = [
            '_structured_output' => true,
            '_structured_strategy' => 'json_mode',
        ];
        $prepared = $this->prepareStructuredPrompt('Generate FAQs.', $variables);

        self::assertStringContainsString('Return ONLY valid JSON.', $prepared);
        self::assertTrue(filter_var($variables['_structured_output'], FILTER_VALIDATE_BOOL));
        self::assertSame('json_mode', $variables['_structured_strategy']);
    }

    public function test_valid_faq_json_still_passes_route_validation(): void
    {
        $runner = $this->runner();
        $method = new \ReflectionMethod(PromptRunnerService::class, 'assertStructuredOutputEligibleForFailover');
        $method->invoke(
            $runner,
            '{"faqs":[{"question":"Q?","answer":"A"}]}',
            'article.faq.generate',
            ['structured_output' => true, 'structured_strategy' => 'json_mode'],
        );

        self::assertTrue(true);
    }

    public function test_contract_is_applied_before_budget_preflight(): void
    {
        $source = (string) file_get_contents(ProjectRoot::addonsPath().'/ai-prompt/src/Services/PromptRunnerService.php');
        $methodStart = strpos($source, 'private function executePlannedRouteAttempt(');
        $contract = strpos($source, 'applyStructuredOutputPromptContract(', $methodStart);
        $preflight = strpos($source, '$preflight = $this->budgetPreflight()', $methodStart);

        self::assertNotFalse($contract);
        self::assertNotFalse($preflight);
        self::assertLessThan($preflight, $contract);
    }

    public function test_execution_scoped_hook_key_overrides_prompt_record_hook(): void
    {
        $runner = (new \ReflectionClass(PromptRunnerService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PromptRunnerService::class, 'effectiveHookKey');
        $prompt = new SeoPrompt();
        $prompt->hook_key = 'regular.text.prompt';

        self::assertSame(
            'article.faq.generate',
            $method->invoke($runner, $prompt, ['_hook_key' => ' article.faq.generate ']),
        );
        self::assertSame('regular.text.prompt', $method->invoke($runner, $prompt, []));
    }

    public function test_faq_invalid_json_is_rejected_inside_router_attempt(): void
    {
        $runner = (string) file_get_contents(ProjectRoot::addonsPath().'/ai-prompt/src/Services/PromptRunnerService.php');
        $adapter = (string) file_get_contents(ProjectRoot::addonsPath().'/ai-prompt/src/PromptHooks/Provider/PromptRunnerProviderAdapter.php');

        self::assertStringContainsString('assertStructuredOutputEligibleForFailover($output', $runner);
        self::assertStringContainsString("throw new InvalidOutput('FAQ_INVALID_JSON:", $runner);
        self::assertStringContainsString("\$variables['_structured_output']", $adapter);
        self::assertStringContainsString("\$variables['_structured_strategy']", $adapter);
    }

    public function test_faq_prompt_is_bounded_and_still_json_only(): void
    {
        $manifest = json_decode((string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/resources/prompt-hooks/v01/article.faq.generate@0.1.0.json',
        ), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($manifest['model']['structured_output']);
        self::assertSame('json', $manifest['output_schema']['type']);
        self::assertStringContainsString('4-6 distinct questions', $manifest['template']['system']);
        self::assertStringContainsString('at most 2 short sentences', $manifest['template']['system']);
        self::assertStringNotContainsString('Markdown', $manifest['template']['system']);
    }

    /** @param array<string, mixed> $variables */
    private function prepareStructuredPrompt(
        string $prompt,
        array $variables,
        string $hookKey = 'article.faq.generate',
    ): string {
        $method = new \ReflectionMethod(PromptRunnerService::class, 'applyStructuredOutputPromptContract');

        return $method->invoke($this->runner(), $prompt, $hookKey, $variables);
    }

    private function runner(): PromptRunnerService
    {
        return (new \ReflectionClass(PromptRunnerService::class))->newInstanceWithoutConstructor();
    }
}
