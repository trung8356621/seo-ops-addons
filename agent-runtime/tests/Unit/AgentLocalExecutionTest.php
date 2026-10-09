<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Answer\AnswerModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\AiSettingsDecisionModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\DecisionModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecisionParser;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Model\AgentModelInputBuilder;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Seo\Contracts\ResolvesSettingsPromptHook;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseParser;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentConfirmedToolExecutor;
use Omnichannel\Addons\Seo\Services\SeoAudit\Agent\SeoAuditAgentReadService;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalPlanner;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessCredential;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessTransport;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessUrlPolicy;
use Omnichannel\Addons\AgentRuntime\Routing\LocalAgentToolRouter;
use Omnichannel\Addons\AgentRuntime\Routing\ToolIntentMatcher;
use Omnichannel\Addons\AgentRuntime\Routing\ToolIntentMatchResult;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AgentLocalExecutionTest extends TestCase
{
    #[Test]
    public function case_a_worst_articles_extracts_limits_and_period_without_a_model(): void
    {
        $decisions = $this->createMock(DecisionModelGateway::class);
        $decisions->expects($this->never())->method('decide');
        $answers = $this->createMock(AnswerModelGateway::class);
        $answers->expects($this->never())->method('complete');
        $coordinator = $this->coordinator($decisions, $answers, new LocalExecutionIntentMatcher(new ToolIntentMatchResult('none', [])));

        $result = $coordinator->send(
            1,
            AgentProjectScope::site(4),
            'tháng 9 này cần sửa những bài nào? gợi ý 30-50 bài',
            [],
        );

        self::assertNull($result->confirmationProposal);
        self::assertNotNull($result->response);
        self::assertSame('seo_audit.worst_articles', $result->executionTrace['capabilities'][0] ?? null);
        self::assertSame(50, $result->executionTrace['parameters']['limit_max'] ?? null);
        self::assertSame(date('Y').'-09', $result->executionTrace['parameters']['period'] ?? null);
        self::assertCount(50, $result->response->blocks[0]['rows']);
        self::assertFalse($result->answerModelCalled);
        self::assertSame(0, $result->executionTrace['external_model_calls']);

        $bundle = new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('articles', 'ok', 'SeoAuditAgentReadService::listArticles?low_score=true&limit=50', [
                'items' => [
                    ['article_ref' => 'article:7', 'title' => 'Balo học sinh', 'seo_score' => 21],
                ],
                'total' => 1,
            ]),
        ]);
        $answered = $coordinator->answerConfirmed(
            1,
            AgentProjectScope::site(4),
            'tháng 9 này cần sửa những bài nào? gợi ý 30-50 bài',
            [],
            $bundle,
            'table',
            'vi',
        );

        self::assertFalse($answered->answerModelCalled);
        self::assertSame(0, $answered->executionTrace['external_model_calls']);
        self::assertSame('table', $answered->response->blocks[0]['type']);
        self::assertSame('article:7', $answered->response->blocks[0]['rows'][0]['article_ref']);
        self::assertSame(21, $answered->response->blocks[0]['rows'][0]['seo_score']);
    }

    #[Test]
    public function case_b_cross_source_analysis_uses_one_answer_model_and_no_decision_model(): void
    {
        $decisions = $this->createMock(DecisionModelGateway::class);
        $decisions->expects($this->never())->method('decide');
        $answers = $this->createMock(AnswerModelGateway::class);
        $answers->expects($this->once())->method('complete')->willReturn(json_encode([
            'message' => 'Hai tập dữ liệu chưa đủ để kết luận nguyên nhân.',
            'blocks' => [['type' => 'markdown', 'text' => 'Hai tập dữ liệu chưa đủ để kết luận nguyên nhân.']],
            'actions' => [],
        ], JSON_THROW_ON_ERROR));
        $coordinator = $this->coordinator($decisions, $answers, new LocalExecutionIntentMatcher(new ToolIntentMatchResult('none', [])));
        $bundle = new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('articles', 'ok', 'articles', ['items' => [['article_ref' => 'article:1', 'seo_score' => 20]]]),
            new RetrievalSource('keywords', 'ok', 'keywords', ['items' => [['keyword' => 'balo', 'clicks' => 3]]]),
        ]);

        $result = $coordinator->answerConfirmed(
            1,
            AgentProjectScope::site(4),
            'Hãy phân tích vì sao bài và từ khóa khác nhau',
            [],
            $bundle,
            'report',
            'vi',
        );

        self::assertTrue($result->answerModelCalled);
        self::assertSame(1, $result->executionTrace['external_model_calls']);
        self::assertStringContainsString('unresolved_requirements', $result->answerInput->exportText());
    }

    #[Test]
    public function case_c_user_selected_models_stay_available_without_a_mandatory_decision_call(): void
    {
        self::assertTrue(class_exists(AiSettingsDecisionModelGateway::class));
        $decisions = $this->createMock(DecisionModelGateway::class);
        $decisions->expects($this->never())->method('decide');
        $answers = $this->createMock(AnswerModelGateway::class);
        $answers->expects($this->once())->method('complete')->willReturn(json_encode([
            'message' => 'Synthesized from retrieved facts.',
            'blocks' => [['type' => 'markdown', 'text' => 'Synthesized from retrieved facts.']],
            'actions' => [],
        ], JSON_THROW_ON_ERROR));
        $coordinator = $this->coordinator($decisions, $answers, new LocalExecutionIntentMatcher(new ToolIntentMatchResult('confident', [
            ['ref' => 'articles.inventory', 'score' => 0.9, 'lexical' => false, 'semantic_score' => 0.9],
        ])));

        $result = $coordinator->send(1, AgentProjectScope::site(4), 'article inventory please explain the gap', []);

        self::assertTrue($result->answerModelCalled);
        self::assertSame(1, $result->executionTrace['external_model_calls']);
        $progress = $coordinator->startIntercepted(AgentProjectScope::site(4), 'article inventory please explain the gap', []);
        self::assertSame('answer', $progress->modelCall?->key);
    }

    #[Test]
    public function case_d_out_of_scope_is_explained_internally(): void
    {
        $decisions = $this->createMock(DecisionModelGateway::class);
        $decisions->expects($this->never())->method('decide');
        $answers = $this->createMock(AnswerModelGateway::class);
        $answers->expects($this->never())->method('complete');
        $coordinator = $this->coordinator($decisions, $answers, new LocalExecutionIntentMatcher(new ToolIntentMatchResult('none', [])));

        $result = $coordinator->send(1, AgentProjectScope::site(4), 'thời tiết hôm nay thế nào', []);

        self::assertSame('out_of_scope', $result->failureCode);
        self::assertFalse($result->answerModelCalled);
        self::assertSame(0, $result->executionTrace['external_model_calls']);
        self::assertStringContainsString('ngoài phạm vi', $result->response->message);
    }

    #[Test]
    public function case_e_ambiguous_counts_ask_instead_of_inventing_parameters(): void
    {
        $decisions = $this->createMock(DecisionModelGateway::class);
        $decisions->expects($this->never())->method('decide');
        $answers = $this->createMock(AnswerModelGateway::class);
        $answers->expects($this->never())->method('complete');
        $transport = new LocalExecutionTransport();
        $coordinator = $this->coordinator($decisions, $answers, new LocalExecutionIntentMatcher(new ToolIntentMatchResult('none', [])), $transport);

        $result = $coordinator->send(1, AgentProjectScope::site(4), 'cần sửa những bài nào, 30 hoặc 50 bài', []);

        self::assertSame('parameters_unresolved', $result->failureCode);
        self::assertNull($result->confirmationProposal);
        self::assertSame([], $transport->methods);
        self::assertSame(0, $result->executionTrace['external_model_calls']);
    }

    #[Test]
    public function case_f_unavailable_capability_does_not_execute(): void
    {
        $decisions = $this->createMock(DecisionModelGateway::class);
        $decisions->expects($this->never())->method('decide');
        $answers = $this->createMock(AnswerModelGateway::class);
        $answers->expects($this->never())->method('complete');
        $transport = new LocalExecutionTransport();
        $coordinator = $this->coordinator($decisions, $answers, new LocalExecutionIntentMatcher(new ToolIntentMatchResult('confident', [
            ['ref' => 'seo_audit.publish', 'score' => 0.99, 'lexical' => false, 'semantic_score' => 0.99],
        ])), $transport);

        $result = $coordinator->send(1, AgentProjectScope::site(4), 'hãy làm điều không có ví dụ tường minh', []);

        self::assertSame('local_tool_router_rejected', $result->failureCode);
        self::assertSame([], $transport->methods);
        self::assertSame(0, $result->executionTrace['external_model_calls']);
    }

    private function coordinator(
        DecisionModelGateway $decisions,
        AnswerModelGateway $answers,
        ToolIntentMatcher $matcher,
        ?LocalExecutionTransport $transport = null,
    ): AgentTurnCoordinator {
        $transport ??= new LocalExecutionTransport();
        $retrieval = new RetrievalExecutor(
            new RetrievalPlanner(),
            new SeoAccessExecutor(
                $transport,
                new class implements SeoAccessCredential {
                    public function bearer(): ?string
                    {
                        return 'svc_live_test';
                    }
                },
                new SeoAccessUrlPolicy(),
                'https://app.example.test',
            ),
        );
        $audit = $this->createMock(SeoAuditAgentReadService::class);
        $audit->method('listArticles')->willReturn([
            'items' => array_map(static fn (int $id): array => [
                'article_ref' => 'article:'.$id,
                'title' => 'Article '.$id,
                'focus_keyword' => 'kw',
                'quality_score' => 20,
                'seo_score' => 20,
                'rankable' => true,
                'system_point' => null,
            ], range(1, 50)),
            'total' => 453,
            'post_type' => null,
        ]);

        return new AgentTurnCoordinator(
            new AgentModelInputBuilder(promptBindings: new class implements ResolvesSettingsPromptHook {
                public function resolveSettingsHook(string $hookKey): SeoPrompt
                {
                    $prompt = new SeoPrompt();
                    $prompt->markdown_content = 'local execution test';

                    return $prompt;
                }
            }),
            $decisions,
            new RetrievalDecisionParser(),
            $retrieval,
            $answers,
            new AgentResponseParser(),
            localToolRouter: new LocalAgentToolRouter($matcher),
            confirmedTools: new AgentConfirmedToolExecutor($retrieval, $audit),
        );
    }
}

final class LocalExecutionIntentMatcher implements ToolIntentMatcher
{
    public function __construct(private readonly ToolIntentMatchResult $result) {}

    public function match(string $query, array $intents): ToolIntentMatchResult
    {
        return $this->result;
    }
}

final class LocalExecutionTransport implements SeoAccessTransport
{
    /** @var list<string> */
    public array $methods = [];

    public function request(string $method, string $url, array $query = [], ?string $bearer = null, ?array $jsonBody = null): array
    {
        $this->methods[] = $method;
        if (strtoupper($method) === 'POST') {
            return ['status' => 200, 'json' => ['data' => [
                'access_url' => 'https://app.example.test/api/v1/services/seo/access/tok',
                'expires_at' => '',
            ]]];
        }

        return ['status' => 200, 'json' => ['data' => ['available' => true]]];
    }
}
