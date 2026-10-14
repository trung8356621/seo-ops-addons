<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Access\AgentActorAccessContext;
use Omnichannel\Addons\AgentRuntime\Access\AgentWorkStatsReader;
use Omnichannel\Addons\AgentRuntime\Access\AgentWorkStatsService;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Response\FactualAgentResponseComposer;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalPlanner;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessCredential;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessTransport;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessUrlPolicy;
use Omnichannel\Addons\AgentRuntime\Routing\DeterministicToolParameters;
use Omnichannel\Addons\AgentRuntime\Routing\LocalAgentToolRouter;
use Omnichannel\Addons\AgentRuntime\Routing\SemanticRoutingConfig;
use Omnichannel\Addons\AgentRuntime\Routing\WeightedRouteEvaluator;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentConfirmedToolExecutor;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentToolConfirmationProposal;
use Omnichannel\Addons\Seo\Services\SeoAudit\Agent\SeoAuditAgentReadService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AgentConstraintPreservationTest extends TestCase
{
    #[Test]
    public function at_most_twenty_keeps_the_topic_and_does_not_force_a_minimum(): void
    {
        $parameters = (new DeterministicToolParameters())->extract(
            'Trong các bài viết về balo học sinh, hãy tìm tối đa 20 bài có điểm SEO thấp để ưu tiên cải thiện.',
        )['parameters'];

        self::assertSame('balo học sinh', $parameters['subject']);
        self::assertSame(20, $parameters['limit_max']);
        self::assertArrayNotHasKey('limit_min', $parameters);
    }

    #[Test]
    public function a_different_topic_is_preserved_and_exact_counts_stay_exact(): void
    {
        $topic = (new DeterministicToolParameters())->extract('Trong các bài viết về vali du lịch, hãy tìm tối đa 20 bài có điểm SEO thấp.')['parameters'];
        self::assertSame('vali du lịch', $topic['subject']);

        $exact = (new DeterministicToolParameters())->extract('Cho tôi 20 bài tệ nhất')['parameters'];
        self::assertSame(20, $exact['limit_min']);
        self::assertSame(20, $exact['limit_max']);
        self::assertArrayNotHasKey('subject', $exact);
    }

    #[Test]
    public function topic_filter_runs_before_the_limit_and_can_return_fewer_than_twenty(): void
    {
        $audit = $this->createMock(SeoAuditAgentReadService::class);
        $audit->expects($this->once())->method('articleIdsForTopicSubject')->with(4, 'balo học sinh', null)->willReturn([
            'supported' => true,
            'ids' => [1, 2],
            'reason' => null,
        ]);
        $audit->expects($this->once())->method('listArticles')->with(
            $this->anything(),
            $this->callback(static function (array $input): bool {
                return $input['article_ids'] === [1, 2]
                    && $input['limit'] === 20
                    && $input['published_only'] === true
                    && $input['sort_score_asc'] === true;
            }),
        )->willReturn([
            'items' => [
                ['article_ref' => 'article:1', 'seo_score' => 11],
                ['article_ref' => 'article:2', 'seo_score' => 18],
            ],
            'total' => 2,
        ]);

        $bundle = (new AgentConfirmedToolExecutor($this->retrieval(), $audit))->execute($this->proposal([
            'subject' => 'balo học sinh',
            'limit_max' => 20,
        ]), 1);

        self::assertCount(2, $bundle->sources[0]->data['items']);
        self::assertSame(2, $bundle->sources[0]->data['total']);
    }

    #[Test]
    public function no_eligible_articles_stay_empty(): void
    {
        $audit = $this->createMock(SeoAuditAgentReadService::class);
        $audit->method('articleIdsForTopicSubject')->willReturn(['supported' => true, 'ids' => [], 'reason' => null]);
        $audit->expects($this->once())->method('listArticles')->with(
            $this->anything(),
            $this->callback(static fn (array $input): bool => $input['article_ids'] === []),
        )->willReturn(['items' => [], 'total' => 0, 'constraint' => ['applied' => true, 'matched' => 0]]);

        $bundle = (new AgentConfirmedToolExecutor($this->retrieval(), $audit))->execute($this->proposal([
            'subject' => 'balo học sinh',
            'limit_max' => 20,
        ]), 1);

        self::assertSame([], $bundle->sources[0]->data['items']);
    }

    #[Test]
    public function unsupported_similarity_does_not_return_an_unfiltered_list(): void
    {
        $audit = $this->createMock(SeoAuditAgentReadService::class);
        $audit->expects($this->never())->method('listArticles');
        $bundle = (new AgentConfirmedToolExecutor($this->retrieval(), $audit))->execute($this->proposal([
            'unsupported_constraint' => 'article_similarity',
            'limit_max' => 20,
        ]), 1);

        self::assertSame([], $bundle->sources[0]->data['items']);
        self::assertStringContainsString('không có article vector', (string) $bundle->sources[0]->data['limitation']);
        $response = (new FactualAgentResponseComposer())->compose($bundle, 'bài giống với balo', 'vi');
        self::assertStringContainsString('không có article vector', (string) $response?->message);
    }

    #[Test]
    public function content_manager_cannot_widen_scope_and_team_questions_are_denied(): void
    {
        $context = new AgentActorAccessContext(9, SeoAccessControl::ROLE_CONTENT_MANAGER, [3], [], false, false);
        self::assertSame([3], $context->authorizedProjectIds([99, 100]));

        $reader = $this->createMock(AgentWorkStatsReader::class);
        $reader->expects($this->never())->method('summarize');
        $response = (new AgentWorkStatsService($reader))->answer($context, 'Ai đang có SEO Score thấp nhất?');
        self::assertStringContainsString('Content Project được giao', $response->message);
    }

    #[Test]
    public function planner_and_manager_do_not_share_archive_visibility(): void
    {
        $planner = new AgentActorAccessContext(2, SeoAccessControl::ROLE_PLANNER, [1], [4], true, false);
        $manager = new AgentActorAccessContext(1, SeoAccessControl::ROLE_MANAGER, [1, 8], [4], true, true);
        self::assertFalse($planner->includeArchivedProjects);
        self::assertTrue($manager->includeArchivedProjects);
        self::assertTrue($planner->teamVisible);
        self::assertTrue($manager->teamVisible);
    }

    #[Test]
    public function role_questions_route_without_calling_the_weighted_evaluator(): void
    {
        $evaluator = $this->createMock(WeightedRouteEvaluator::class);
        $evaluator->expects($this->never())->method('evaluate');
        $route = (new LocalAgentToolRouter($evaluator, new SemanticRoutingConfig()))->route('Tháng này tôi cần làm những gì?');
        self::assertSame('work.stats', $route->capability);
        self::assertFalse($route->answerModelRequired);
    }

    /**
     * @param  array<string, int|string>  $parameters
     */
    private function proposal(array $parameters): AgentToolConfirmationProposal
    {
        return new AgentToolConfirmationProposal(
            'Trong các bài viết về balo học sinh, hãy tìm tối đa 20 bài có điểm SEO thấp để ưu tiên cải thiện.',
            'seo_audit.worst_articles',
            ['seo_audit.worst_articles'],
            $parameters,
            'table',
            'vi',
            ['seo_audit.worst_articles'],
            AgentProjectScope::site(4)->toArray(),
        );
    }

    private function retrieval(): RetrievalExecutor
    {
        return new RetrievalExecutor(
            new RetrievalPlanner(),
            new SeoAccessExecutor(
                new class implements SeoAccessTransport {
                    public function request(string $method, string $url, array $query = [], ?string $bearer = null, ?array $jsonBody = null): array
                    {
                        throw new \RuntimeException('SEO Access must not be called.');
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
        );
    }
}
