<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseParser;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseRejected;
use Omnichannel\Addons\AgentRuntime\Response\FactualAgentResponseComposer;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;
use Tests\TestCase;

final class AgentAnswerRecoveryTest extends TestCase
{
    public function test_row_indexes_are_not_treated_as_measurements_and_invented_scores_are_dropped(): void
    {
        $parser = new AgentResponseParser();
        $bundle = new RetrievalBundle(AgentProjectScope::site(7), [
            new RetrievalSource('articles', 'ok', 'SeoAuditAgentReadService::listArticles', [
                'items' => [['title' => 'Guide', 'seo_score' => 41]],
            ]),
        ]);

        $indexed = $parser->parse(json_encode([
            'message' => 'Verified rows.',
            'blocks' => [[
                'type' => 'table',
                'columns' => [
                    ['key' => 'stt', 'label' => 'STT'],
                    ['key' => 'title', 'label' => 'Title'],
                    ['key' => 'seo_score', 'label' => 'SEO score'],
                ],
                'rows' => [['stt' => 1, 'title' => 'Guide', 'seo_score' => 41]],
            ]],
        ], JSON_THROW_ON_ERROR), $bundle);
        self::assertSame([], $parser->lastRejections());
        self::assertSame(41, $indexed->blocks[0]['rows'][0]['seo_score']);

        $invented = $parser->parse(json_encode([
            'message' => 'Narrative without numbers.',
            'blocks' => [
                ['type' => 'markdown', 'text' => 'The retrieved article is listed below.'],
                ['type' => 'table', 'columns' => [['key' => 'seo_score', 'label' => 'SEO score']], 'rows' => [['seo_score' => 99]]],
            ],
        ], JSON_THROW_ON_ERROR), $bundle);
        self::assertSame('Table value is not present in retrieval evidence.', $parser->lastRejections()[0]);
        self::assertSame('markdown', $invented->blocks[0]['type']);
        self::assertStringNotContainsString('99', json_encode($invented->blocks));
    }

    public function test_invalid_json_still_throws_and_verified_facts_do_not_invent_scores(): void
    {
        $parser = new AgentResponseParser();
        $bundle = new RetrievalBundle(AgentProjectScope::site(7), [
            new RetrievalSource('articles', 'ok', 'GET /articles', [
                'items' => [['title' => 'Guide', 'seo_score' => 41]],
            ]),
        ]);
        try {
            $parser->parse('not json', $bundle);
            self::fail('Malformed JSON must stay rejected.');
        } catch (AgentResponseRejected $error) {
            self::assertStringContainsString('not JSON', $error->getMessage());
        }

        $facts = (new FactualAgentResponseComposer())->verifiedFacts($bundle, 'en');
        self::assertNotNull($facts);
        self::assertSame(41, $facts->blocks[0]['rows'][0]['seo_score']);
        self::assertStringNotContainsString('99', json_encode($facts->toArray()));
    }
}
