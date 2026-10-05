<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Model\AgentModelInputBuilder;
use Omnichannel\Addons\AgentRuntime\Model\AgentModelEvidenceSanitizer;
use Omnichannel\Addons\AgentRuntime\Model\SecretRedactor;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;
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
            'text',
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

    public function test_answer_input_hides_navigation_metadata_but_preserves_semantic_and_contact_evidence(): void
    {
        $scope = AgentProjectScope::site(6);
        $siteUrl = 'https://mayhopphat.com/may-cap/';
        $socialUrl = 'https://facebook.com/mayhopphat';
        $topicHref = 'https://seo-ops.test/seo/keywords/clusters/2382?site_id=6';
        $detailHref = '/api/v1/access/tmp/keywords/topics/topic:2382';
        $bundle = new RetrievalBundle($scope, [
            new RetrievalSource('site', 'ok', 'GET /site', [
                'contact' => ['socials' => [$socialUrl]],
                'important_pages' => ['items' => [[
                    'url' => $siteUrl,
                    'title' => 'May cặp',
                    'page_type' => 'product_category',
                    'keyword' => 'may cặp',
                ]]],
            ]),
            new RetrievalSource('topics', 'ok', 'GET /keywords', ['topics' => [[
                'topic_ref' => 'topic:2382',
                'name' => 'Backpack Factory',
                'ui_href' => $topicHref,
                'detail_href' => $detailHref,
            ]]]),
            new RetrievalSource('gsc', 'unavailable', 'GET /gsc', [
                'latest_available' => ['period' => '2026-07', 'href' => '/api/v1/access/tmp/gsc?period=2026-07'],
            ]),
        ]);
        $resolver = new InMemoryAgentPromptResolver(['agent.response.compose' => 'response instruction']);

        $input = (new AgentModelInputBuilder(new SecretRedactor(), $resolver))->buildAnswerInput(
            $scope,
            'Analyze the site',
            [],
            $bundle,
            'report',
        )->exportText();

        self::assertStringNotContainsString($siteUrl, $input);
        self::assertStringNotContainsString('/may-cap/', $input);
        self::assertStringNotContainsString($topicHref, $input);
        self::assertStringNotContainsString('ui_href', $input);
        self::assertStringNotContainsString('detail_href', $input);
        self::assertStringNotContainsString('/api/v1/access/tmp/gsc', $input);
        self::assertStringContainsString('May cặp', $input);
        self::assertStringContainsString('product_category', $input);
        self::assertStringContainsString('may cặp', $input);
        self::assertStringContainsString('topic:2382', $input);
        self::assertStringContainsString('Backpack Factory', $input);
        self::assertStringContainsString($socialUrl, $input);

        $raw = $bundle->toArray();
        self::assertSame($siteUrl, $raw['sources'][0]['data']['important_pages']['items'][0]['url']);
        self::assertSame($topicHref, $raw['sources'][1]['data']['topics'][0]['ui_href']);
        self::assertSame($detailHref, $raw['sources'][1]['data']['topics'][0]['detail_href']);
    }

    public function test_sanitizer_is_source_aware_for_url_fields(): void
    {
        $scope = AgentProjectScope::site(6);
        $externalEvidenceUrl = 'https://external.example/evidence';
        $bundle = new RetrievalBundle($scope, [
            new RetrievalSource('external_links', 'ok', 'GET /external-links', [
                'links' => [['url' => $externalEvidenceUrl, 'label' => 'External evidence']],
            ]),
        ]);

        $sanitized = (new AgentModelEvidenceSanitizer())->sanitize($bundle);

        self::assertSame($externalEvidenceUrl, $sanitized['sources'][0]['data']['links'][0]['url']);
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
