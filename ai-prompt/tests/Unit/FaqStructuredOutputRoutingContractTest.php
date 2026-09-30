<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class FaqStructuredOutputRoutingContractTest extends TestCase
{
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
}
