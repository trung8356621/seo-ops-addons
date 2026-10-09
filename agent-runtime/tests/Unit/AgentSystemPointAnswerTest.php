<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Answer\AnswerModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\DecisionModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecisionParser;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Http\AgentRuntimeController;
use Omnichannel\Addons\AgentRuntime\Model\AgentModelInputBuilder;
use Omnichannel\Addons\AgentRuntime\Projects\SiteDirectory;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseParser;
use Omnichannel\Addons\AgentRuntime\Response\FactualAgentResponseComposer;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalPlanner;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessCredential;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessTransport;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessUrlPolicy;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeItemResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeService;
use Omnichannel\Addons\Seo\Contracts\ResolvesSettingsPromptHook;
use Omnichannel\Addons\Seo\Services\SeoAudit\Agent\SeoAuditAgentReadService;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class AgentSystemPointAnswerTest extends TestCase
{
    #[Test]
    public function rankable_low_score_stays_and_system_points_stay_out_of_ordinary_ranking(): void
    {
        $service = (new ReflectionClass(SeoAuditAgentReadService::class))->newInstanceWithoutConstructor();
        $project = new ReflectionMethod($service, 'projectRetrievedArticle');
        $keep = new ReflectionMethod($service, 'keepRetrievedArticle');

        $scoreFive = [
            'id' => 5,
            'title' => 'Low',
            'rankable' => true,
            'quality_score' => 5,
            'system_point' => null,
            'score' => 5,
            'has_focus_keyword' => true,
            'focus_keyword' => 'balo',
        ];
        $projected = $project->invoke($service, $scoreFive, null);
        self::assertTrue($keep->invoke($service, $scoreFive, true, []));
        self::assertSame(5, $projected['seo_score']);
        self::assertSame(5, $projected['quality_score']);
        self::assertTrue($projected['rankable']);
        self::assertNull($projected['system_point']);
        self::assertSame('article:5', $projected['article_ref']);

        $missing = [
            'id' => 3,
            'rankable' => false,
            'system_point' => 3,
            'quality_score' => null,
            'score' => 0,
            'has_focus_keyword' => false,
        ];
        self::assertFalse($keep->invoke($service, $missing, true, []));
        self::assertTrue($keep->invoke($service, $missing, true, [SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD]));
        $missingProjected = $project->invoke($service, $missing, null);
        self::assertSame(3, $missingProjected['system_point']);
        self::assertNull($missingProjected['quality_score']);
        self::assertNull($missingProjected['seo_score']);

        foreach ([1, 2] as $code) {
            $special = ['id' => $code, 'rankable' => false, 'system_point' => $code, 'quality_score' => null, 'score' => $code];
            self::assertFalse($keep->invoke($service, $special, true, []));
        }

        $collision = ['id' => 9, 'rankable' => true, 'quality_score' => 9, 'system_point' => null, 'score' => 9];
        self::assertTrue($keep->invoke($service, $collision, true, []));
        self::assertSame(9, $project->invoke($service, $collision, null)['seo_score']);
    }

    #[Test]
    public function empty_optimization_set_has_no_draft_table(): void
    {
        $response = (new FactualAgentResponseComposer())->compose($this->draftBundle([]), 'Những bài nào có điểm SEO thấp', 'vi');
        self::assertSame('markdown', $response->blocks[0]['type']);
        self::assertArrayNotHasKey('actionable', $response->blocks[0]);
        self::assertStringNotContainsString('Đưa 0', json_encode($response->toArray(), JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function model_score_is_replaced_unknown_refs_are_rejected_and_missing_refs_are_not_invented(): void
    {
        $parser = new AgentResponseParser();
        $bundle = $this->draftBundle([[
            'article_ref' => 'article:7',
            'title' => 'Balo thật',
            'focus_keyword' => 'balo',
            'seo_score' => 99,
            'quality_score' => 21,
            'rankable' => true,
            'system_point' => null,
        ], [
            'article_ref' => 'article:8',
            'title' => 'Khác',
            'focus_keyword' => 'khac',
            'seo_score' => 99,
            'quality_score' => 99,
            'rankable' => true,
            'system_point' => null,
        ]]);

        $corrected = $parser->parse($this->tableJson([[
            'article_ref' => 'article:7',
            'title' => 'Sai tiêu đề',
            'seo_score' => 99,
            'item' => $this->item('article:7', 'Sai tiêu đề', 'sai'),
        ]]), $bundle);
        self::assertSame([], $parser->lastRejections());
        self::assertSame(21, $corrected->blocks[0]['rows'][0]['seo_score']);
        self::assertSame('Balo thật', $corrected->blocks[0]['rows'][0]['item']['title']);
        self::assertSame('balo', $corrected->blocks[0]['rows'][0]['item']['keyword']);

        $parser->parse($this->tableJson([[
            'title' => 'Đoán từ tiêu đề',
            'item' => [
                'id' => 'title:Đoán từ tiêu đề',
                'type' => 'improve',
                'title' => 'Đoán từ tiêu đề',
                'keyword' => 'doan',
                'reasons' => [],
                'source' => ['type' => 'seo_audit', 'ref' => 'title:Đoán từ tiêu đề'],
            ],
        ]]), $bundle);
        self::assertSame('Existing-article recommendations require article_ref.', $parser->lastRejections()[0]);

        $omitted = $parser->parse(json_encode([
            'message' => 'Không có định danh.',
            'blocks' => [[
                'type' => 'table',
                'columns' => [['key' => 'title', 'label' => 'Title']],
                'actionable' => ['action' => 'content_project.draft.intake', 'site_ref' => 'site:4'],
                'rows' => [[
                    'title' => 'Đoán từ tiêu đề',
                    'item' => [
                        'id' => 'title:Đoán từ tiêu đề',
                        'type' => 'improve',
                        'title' => 'Đoán từ tiêu đề',
                        'keyword' => 'doan',
                        'reasons' => [],
                        'source' => ['type' => 'seo_audit', 'ref' => 'title:Đoán từ tiêu đề'],
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR), $bundle);
        self::assertSame([], $omitted->blocks);

        $parser->parse($this->tableJson([[
            'article_ref' => 'article:99',
            'title' => 'Site khác',
            'seo_score' => 21,
            'item' => $this->item('article:99', 'Site khác', 'khac'),
        ]]), $bundle);
        self::assertSame('Article ref is not in the authorized retrieval set.', $parser->lastRejections()[0]);
    }

    #[Test]
    public function malformed_model_table_falls_back_to_verified_rows_without_a_second_model_call(): void
    {
        $answers = $this->createMock(AnswerModelGateway::class);
        $answers->expects($this->once())->method('complete')->willReturn('not json');
        $coordinator = $this->coordinator($answers);
        $bundle = new RetrievalBundle(AgentProjectScope::site(4), [
            $this->draftBundle([[
                'article_ref' => 'article:7',
                'title' => 'Balo',
                'focus_keyword' => 'balo',
                'seo_score' => 21,
                'quality_score' => 21,
                'rankable' => true,
                'system_point' => null,
                'reason_labels' => ['Thin content'],
            ]])->sources[0],
            new RetrievalSource('keywords', 'ok', 'keywords', ['items' => [['keyword' => 'balo']]]),
        ]);
        $result = $coordinator->answerConfirmed(1, AgentProjectScope::site(4), 'phân tích điểm thấp', [], $bundle, 'table', 'vi');

        self::assertTrue($result->answerModelCalled);
        self::assertSame(1, $result->executionTrace['external_model_calls']);
        $table = null;
        foreach ($result->response->blocks as $block) {
            if (($block['type'] ?? '') === 'table') {
                $table = $block;
            }
        }
        self::assertNotNull($table);
        self::assertSame('article:7', $table['rows'][0]['article_ref']);
        self::assertSame(21, $table['rows'][0]['seo_score']);
        self::assertStringNotContainsString('99', json_encode($table));
    }

    #[Test]
    public function factual_worst_articles_do_not_call_the_answer_model(): void
    {
        $answers = $this->createMock(AnswerModelGateway::class);
        $answers->expects($this->never())->method('complete');
        $coordinator = $this->coordinator($answers);
        $result = $coordinator->answerConfirmed(
            1,
            AgentProjectScope::site(4),
            'Những bài nào có điểm SEO thấp',
            [],
            $this->draftBundle([[
                'article_ref' => 'article:4',
                'title' => 'A',
                'focus_keyword' => 'a',
                'seo_score' => 15,
                'quality_score' => 15,
                'rankable' => true,
                'system_point' => null,
            ], [
                'article_ref' => 'article:5',
                'title' => 'B',
                'focus_keyword' => 'b',
                'seo_score' => 30,
                'quality_score' => 30,
                'rankable' => true,
                'system_point' => null,
            ]]),
            'table',
            'vi',
        );

        self::assertFalse($result->answerModelCalled);
        self::assertSame(0, $result->executionTrace['external_model_calls']);
        self::assertSame(['article:4', 'article:5'], array_column($result->response->blocks[0]['rows'], 'article_ref'));
    }

    #[Test]
    public function verified_rows_can_be_submitted_to_draft_intake(): void
    {
        $response = (new FactualAgentResponseComposer())->compose($this->draftBundle([[
            'article_ref' => 'article:4',
            'title' => 'A',
            'focus_keyword' => 'a',
            'quality_score' => 15,
            'rankable' => true,
            'system_point' => null,
        ], [
            'article_ref' => 'article:5',
            'title' => 'B',
            'focus_keyword' => 'b',
            'quality_score' => 30,
            'rankable' => true,
            'system_point' => null,
        ], [
            'article_ref' => 'article:3',
            'title' => 'Thiếu keyword',
            'system_point' => 3,
            'rankable' => false,
            'quality_score' => null,
            'seo_score' => 0,
        ]]), 'bài điểm thấp', 'vi');

        self::assertSame(['article:4', 'article:5'], array_column($response->blocks[0]['rows'], 'article_ref'));
        $items = array_map(static fn (array $row): array => $row['item'], $response->blocks[0]['rows']);
        $intake = $this->createMock(ServiceApiDraftIntakeService::class);
        $intake->expects($this->once())->method('intake')->willReturnCallback(
            function (array $payload) {
                self::assertSame(['article:4', 'article:5'], array_column($payload['items'], 'article_ref'));

                return new ServiceApiDraftIntakeResult('project:3', 'site:4', 2, 2, 0, 0, [
                    new ServiceApiDraftIntakeItemResult(0, 'added', 'item:1'),
                    new ServiceApiDraftIntakeItemResult(1, 'added', 'item:2'),
                ]);
            }
        );
        $sites = new class implements SiteDirectory {
            public function listActiveSites(?int $userId = null): array
            {
                return [];
            }

            public function isSiteVisible(int $siteId, ?int $userId = null): bool
            {
                return $siteId === 4;
            }
        };
        $http = Request::create('/agent-runtime/draft-intake', 'POST', ['site_id' => 4, 'items' => $items]);
        $http->setUserResolver(static fn () => new class {
            public int $id = 1;
        });
        $body = (new AgentRuntimeController())->draftIntake($http, $sites, $intake)->getData(true);
        self::assertTrue($body['data']['complete']);
        self::assertSame(2, $body['data']['added']);
    }

    #[Test]
    public function unusable_retrieval_falls_back_to_text(): void
    {
        $answers = $this->createMock(AnswerModelGateway::class);
        $answers->expects($this->once())->method('complete')->willReturn('{');
        $bundle = new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('notes', 'ok', 'notes', ['nested' => ['x' => 1]]),
            new RetrievalSource('other', 'ok', 'other', ['nested' => ['y' => 2]]),
        ]);
        $result = $this->coordinator($answers)->answerConfirmed(1, AgentProjectScope::site(4), 'phân tích vì sao', [], $bundle, 'text', 'vi');
        $types = array_column($result->response->blocks, 'type');
        self::assertNotContains('table', $types);
        self::assertSame(1, $result->executionTrace['external_model_calls']);
    }

    /** @param list<array<string, mixed>> $items */
    private function draftBundle(array $items): RetrievalBundle
    {
        return new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('articles', 'ok', 'list', [
                'draft_action' => 'content_project.draft.intake',
                'draft_item_type' => 'improve',
                'draft_source' => 'seo_audit',
                'items' => $items,
            ]),
        ]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function tableJson(array $rows): string
    {
        return json_encode([
            'message' => 'Đề xuất.',
            'blocks' => [[
                'type' => 'table',
                'columns' => [
                    ['key' => 'article_ref', 'label' => 'Article'],
                    ['key' => 'title', 'label' => 'Title'],
                    ['key' => 'seo_score', 'label' => 'SEO score'],
                ],
                'actionable' => ['action' => 'content_project.draft.intake', 'site_ref' => 'site:4'],
                'rows' => $rows,
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function item(string $ref, string $title, string $keyword): array
    {
        return [
            'id' => $ref,
            'type' => 'improve',
            'article_ref' => $ref,
            'title' => $title,
            'keyword' => $keyword,
            'reasons' => [],
            'source' => ['type' => 'seo_audit', 'ref' => $ref],
        ];
    }

    private function coordinator(AnswerModelGateway $answers): AgentTurnCoordinator
    {
        return new AgentTurnCoordinator(
            new AgentModelInputBuilder(promptBindings: new class implements ResolvesSettingsPromptHook {
                public function resolveSettingsHook(string $hookKey): SeoPrompt
                {
                    $prompt = new SeoPrompt();
                    $prompt->markdown_content = 'test';

                    return $prompt;
                }
            }),
            $this->createMock(DecisionModelGateway::class),
            new RetrievalDecisionParser(),
            new RetrievalExecutor(
                new RetrievalPlanner(),
                new SeoAccessExecutor(
                    new class implements SeoAccessTransport {
                        public function request(string $method, string $url, array $query = [], ?string $bearer = null, ?array $jsonBody = null): array
                        {
                            return [];
                        }
                    },
                    new class implements SeoAccessCredential {
                        public function bearer(): ?string
                        {
                            return null;
                        }
                    },
                    new SeoAccessUrlPolicy(),
                    'https://app.example.test',
                ),
            ),
            $answers,
            new AgentResponseParser(),
        );
    }
}
