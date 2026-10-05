<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Navigation\AgentInternalLinkResolver;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseParser;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseRejected;
use Omnichannel\Addons\AgentRuntime\Retrieval\AgentEvidenceLinkEnricher;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultAgentRuntimePromptInstaller;
use Omnichannel\Addons\SearchIntelligence\Services\AgentTopicInternalLinkResolver;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Tests\TestCase;

final class InternalEntityLinkTest extends TestCase
{
    public function test_topic_ref_resolves_to_canonical_site_scoped_url(): void
    {
        $resolver = new AgentTopicInternalLinkResolver(new DomainContextResolver());

        $url = $resolver->resolve('topic:82', AgentProjectScope::site(6));

        self::assertNotNull($url);
        self::assertMatchesRegularExpression('#^https?://#', $url);
        self::assertStringContainsString('/seo/keywords/clusters/82', $url);
        self::assertStringContainsString('site_id=6', $url);
        self::assertNull($resolver->resolve('topic:0', AgentProjectScope::site(6)));
        self::assertNull($resolver->resolve('keyword:82', AgentProjectScope::site(6)));
        self::assertNull($resolver->resolve('topic:82', AgentProjectScope::global()));
    }

    public function test_topic_evidence_is_enriched_without_changing_existing_fields(): void
    {
        $resolver = new class implements AgentInternalLinkResolver {
            public function resolve(string $entityRef, AgentProjectScope $scope): ?string
            {
                return $entityRef === 'topic:82' && $scope->siteId === 6
                    ? 'https://seo-ops.test/seo/keywords/clusters/82?site_id=6'
                    : null;
            }
        };
        $source = new RetrievalSource('topics', 'ok', 'GET /keywords', [
            'topics' => [[
                'topic_ref' => 'topic:82',
                'id' => 82,
                'name' => 'Balo qua tang tai chinh',
                'article_count' => 0,
                'detail_href' => '/api/v1/access/tmp/keywords/topics/topic:82',
            ]],
        ]);

        $enriched = (new AgentEvidenceLinkEnricher($resolver))->enrich([$source], AgentProjectScope::site(6));
        $topic = $enriched[0]->data['topics'][0];

        self::assertSame('topic:82', $topic['topic_ref']);
        self::assertSame('Balo qua tang tai chinh', $topic['name']);
        self::assertSame(0, $topic['article_count']);
        self::assertSame('/api/v1/access/tmp/keywords/topics/topic:82', $topic['detail_href']);
        self::assertSame('https://seo-ops.test/seo/keywords/clusters/82?site_id=6', $topic['ui_href']);
    }

    public function test_parser_accepts_evidence_backed_internal_markdown_link(): void
    {
        $href = 'https://seo-ops.test/seo/keywords/clusters/82?site_id=6';
        $response = (new AgentResponseParser())->parse(json_encode([
            'message' => 'Topic found.',
            'blocks' => [['type' => 'markdown', 'text' => "[Topic Name]({$href}) — 0 articles"]],
            'actions' => [],
        ], JSON_THROW_ON_ERROR), $this->bundleWithHref($href));

        self::assertSame("[Topic Name]({$href}) — 0 articles", $response->blocks[0]['text']);
    }

    public function test_parser_rejects_invented_internal_markdown_link(): void
    {
        $trusted = 'https://seo-ops.test/seo/keywords/clusters/82?site_id=6';
        $invented = 'https://seo-ops.test/seo/keywords/clusters/83?site_id=6';

        $this->expectException(AgentResponseRejected::class);
        (new AgentResponseParser())->parse(json_encode([
            'message' => 'Topic found.',
            'blocks' => [['type' => 'markdown', 'text' => "[Topic Name]({$invented})"]],
            'actions' => [],
        ], JSON_THROW_ON_ERROR), $this->bundleWithHref($trusted));
    }

    public function test_parser_rejects_unsafe_markdown_uri_scheme(): void
    {
        $this->expectException(AgentResponseRejected::class);
        (new AgentResponseParser())->parse(json_encode([
            'message' => 'Bad link.',
            'blocks' => [['type' => 'markdown', 'text' => '[Click](javascript:alert(1))']],
            'actions' => [],
        ], JSON_THROW_ON_ERROR), new RetrievalBundle(AgentProjectScope::site(6), []));
    }

    public function test_response_prompt_instructs_exact_trusted_ui_href_usage(): void
    {
        $prompt = DefaultAgentRuntimePromptInstaller::canonicalDefaultMarkdown('response');

        self::assertStringContainsString('trusted ui_href', $prompt);
        self::assertStringContainsString('using exactly that ui_href', $prompt);
        self::assertStringContainsString('Never invent or alter an internal URL', $prompt);
    }

    private function bundleWithHref(string $href): RetrievalBundle
    {
        return new RetrievalBundle(AgentProjectScope::site(6), [
            new RetrievalSource('topics', 'ok', 'GET /keywords', [
                'topics' => [['topic_ref' => 'topic:82', 'name' => 'Topic Name', 'ui_href' => $href]],
            ]),
        ]);
    }
}
