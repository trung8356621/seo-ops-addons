<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Model\AgentModelInputBuilder;
use Omnichannel\Addons\AgentRuntime\Model\SecretRedactor;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Seo\Contracts\ResolvesSettingsPromptHook;
use PHPUnit\Framework\TestCase;

final class AgentModelInputBuilderPromptTest extends TestCase
{
    public function test_routing_and_answer_inputs_use_bound_prompt_markdown(): void
    {
        $resolver = new InMemoryAgentPromptResolver([
            'agent.routing.decide' => 'editable routing instruction',
            'agent.response.compose' => 'editable response instruction',
        ]);
        $builder = new AgentModelInputBuilder(new SecretRedactor, $resolver);

        $routing = $builder->buildRoutingInput(AgentProjectScope::site(7), 'route this', [])->messages;
        $answer = $builder->buildAnswerInput(
            AgentProjectScope::site(7),
            'answer this',
            [],
            new RetrievalBundle(AgentProjectScope::site(7), []),
        )->messages;

        self::assertSame('editable routing instruction', $routing[0]['content']);
        self::assertSame('editable response instruction', $answer[0]['content']);
    }

    public function test_next_model_input_uses_edited_markdown(): void
    {
        $resolver = new InMemoryAgentPromptResolver(['agent.routing.decide' => 'before edit']);
        $builder = new AgentModelInputBuilder(new SecretRedactor, $resolver);
        $scope = AgentProjectScope::site(7);

        self::assertSame('before edit', $builder->buildRoutingInput($scope, 'route', [])->messages[0]['content']);
        $resolver->markdown['agent.routing.decide'] = 'after edit';
        self::assertSame('after edit', $builder->buildRoutingInput($scope, 'route', [])->messages[0]['content']);
    }

    public function test_missing_binding_fails_closed(): void
    {
        $builder = new AgentModelInputBuilder(new SecretRedactor, new InMemoryAgentPromptResolver([]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing prompt binding: agent.routing.decide');
        $builder->buildRoutingInput(AgentProjectScope::site(7), 'route', []);
    }

    public function test_runtime_has_no_hardcoded_instruction_source(): void
    {
        $root = dirname(__DIR__, 2);
        $source = (string) file_get_contents($root.'/src/Model/AgentModelInputBuilder.php');
        self::assertStringNotContainsString('RoutingRuntimeInstructions::system()', $source);
        self::assertStringNotContainsString('AnswerRuntimeInstructions::system()', $source);
        self::assertFileDoesNotExist($root.'/src/Decision/RoutingRuntimeInstructions.php');
        self::assertFileDoesNotExist($root.'/src/Answer/AnswerRuntimeInstructions.php');
    }
}

final class InMemoryAgentPromptResolver implements ResolvesSettingsPromptHook
{
    /** @param array<string, string> $markdown */
    public function __construct(public array $markdown) {}

    public function resolveSettingsHook(string $hookKey): SeoPrompt
    {
        if (! array_key_exists($hookKey, $this->markdown)) {
            throw new \RuntimeException("Missing prompt binding: {$hookKey}");
        }

        $prompt = new SeoPrompt;
        $prompt->forceFill(['hook_key' => $hookKey, 'markdown_content' => $this->markdown[$hookKey]]);

        return $prompt;
    }
}
