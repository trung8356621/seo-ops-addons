<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use DateTimeImmutable;
use Omnichannel\Addons\AgentRuntime\Access\AgentActorAccessContext;
use Omnichannel\Addons\AgentRuntime\Access\AgentWorkStatsReader;
use Omnichannel\Addons\AgentRuntime\Access\AgentWorkStatsService;
use Omnichannel\Addons\AgentRuntime\Access\WorkStatsWindow;
use Omnichannel\Addons\AgentRuntime\Routing\LocalAgentToolRouter;
use Omnichannel\Addons\AgentRuntime\Routing\SemanticRoutingConfig;
use Omnichannel\Addons\AgentRuntime\Routing\WeightedRouteEvaluator;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AgentWorkStatsScopeTest extends TestCase
{
    #[Test]
    public function missing_period_is_all_time_and_named_months_stay_explicit(): void
    {
        $now = new DateTimeImmutable('2026-10-14');
        $open = WorkStatsWindow::fromMessage('Tôi còn nợ mấy bài?', $now);
        self::assertSame('all', $open->mode);
        self::assertSame('', $open->phrase);

        $current = WorkStatsWindow::fromMessage('Tháng này tôi còn bao nhiêu bài chưa hoàn thành?', $now);
        self::assertSame('2026-10-01', $current->from);
        self::assertSame('2026-10-31', $current->to);

        $previous = WorkStatsWindow::fromMessage('Tháng trước tôi còn bao nhiêu việc chưa xong?', $now);
        self::assertSame('2026-09-01', $previous->from);
        self::assertSame('2026-09-30', $previous->to);

        $range = WorkStatsWindow::fromMessage('Tôi còn bao nhiêu bài chưa hoàn thành từ 2026-01-02 đến 2026-03-15?', $now);
        self::assertSame('range', $range->mode);
        self::assertSame('2026-01-02', $range->from);
        self::assertSame('2026-03-15', $range->to);
    }

    #[Test]
    public function remaining_answer_does_not_assume_the_current_month(): void
    {
        $context = new AgentActorAccessContext(9, SeoAccessControl::ROLE_CONTENT_MANAGER, [3], [4], true, false);
        $service = new AgentWorkStatsService($this->reader(4));
        $allTime = $service->answer($context, 'Tôi còn bao nhiêu bài chưa hoàn thành?');
        self::assertSame('Bạn còn 4 bài chưa hoàn thành.', $allTime->message);

        $month = $service->answer($context, 'Tháng này tôi còn bao nhiêu việc chưa xong?');
        self::assertStringContainsString('trong tháng này', $month->message);
        self::assertStringNotContainsString('trong tháng này.', $service->answer($context, 'Tôi còn nợ mấy bài?')->message);
    }

    #[Test]
    public function outstanding_breakdown_sums_to_the_aggregate_and_zero_has_no_rows(): void
    {
        $context = new AgentActorAccessContext(9, SeoAccessControl::ROLE_CONTENT_MANAGER, [3, 8], [4], false, false);
        $service = new AgentWorkStatsService($this->reader(30, [], [
            ['id' => 3, 'name' => 'Tháng 10', 'month' => '10/2026', 'outstanding' => 12],
            ['id' => 8, 'name' => 'Tháng 9', 'month' => '09/2026', 'outstanding' => 18],
        ]));
        $response = $service->answer($context, 'Tôi còn bao nhiêu bài viết chưa hoàn thành?');
        $table = $response->blocks[1];
        $sum = array_sum(array_column($table['rows'], 'outstanding'));

        self::assertSame('Bạn còn 30 bài chưa hoàn thành.', $response->message);
        self::assertSame('table', $table['type']);
        self::assertSame(30, $sum);
        self::assertSame('Tháng 10', $table['rows'][0]['project']['label'] ?? $table['rows'][0]['project']);
        self::assertArrayNotHasKey('id', $table['rows'][0]);

        $empty = (new AgentWorkStatsService($this->reader(0)))->answer($context, 'Tôi còn bao nhiêu bài chưa hoàn thành?');
        self::assertCount(1, $empty->blocks);
        self::assertSame('Bạn còn 0 bài chưa hoàn thành.', $empty->message);
    }

    #[Test]
    public function leaderboard_keeps_missing_scores_unranked(): void
    {
        $context = new AgentActorAccessContext(9, SeoAccessControl::ROLE_CONTENT_MANAGER, [3], [4], true, false);
        $response = (new AgentWorkStatsService($this->reader(0, [[
            'name' => 'An',
            'assigned' => 2,
            'completed' => 1,
            'average_seo_score' => 70.0,
            'rank' => 1,
        ], [
            'name' => 'Bình',
            'assigned' => 2,
            'completed' => 2,
            'average_seo_score' => null,
            'rank' => null,
        ]])))->answer($context, 'Xếp hạng SEO Score các thành viên trong team.');

        self::assertStringContainsString('An', $response->message);
        self::assertStringContainsString('Chưa có dữ liệu', $response->message);
        self::assertStringNotContainsString('| 0 |', $response->message);
        self::assertSame([], $response->actions);
    }

    #[Test]
    public function colloquial_work_and_team_questions_route_to_work_stats(): void
    {
        $evaluator = $this->createMock(WeightedRouteEvaluator::class);
        $evaluator->expects($this->never())->method('evaluate');
        $router = new LocalAgentToolRouter($evaluator, new SemanticRoutingConfig());
        foreach ([
            'Tôi còn nợ mấy bài?',
            'Tôi còn bao nhiêu việc chưa xong?',
            'Ai đang có SEO Score thấp nhất trong team?',
            'Xếp hạng SEO Score các thành viên trong team.',
            'Ai hoàn thành nhiều bài nhất tháng này?',
        ] as $message) {
            $route = $router->route($message);
            self::assertSame('work.stats', $route->capability, $message);
            self::assertFalse($route->answerModelRequired, $message);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $members
     */
    /**
     * @param  list<array<string, mixed>>  $members
     * @param  list<array<string, mixed>>  $projects
     */
    private function reader(int $open, array $members = [], array $projects = []): AgentWorkStatsReader
    {
        return new class($open, $members, $projects) implements AgentWorkStatsReader
        {
            /**
             * @param  list<array<string, mixed>>  $members
             * @param  list<array<string, mixed>>  $projects
             */
            public function __construct(private int $open, private array $members, private array $projects) {}

            public function summarize(AgentActorAccessContext $context, string $kind, WorkStatsWindow $window): array
            {
                return [
                    'open_tasks' => $this->open,
                    'completed_tasks' => 0,
                    'average_seo_score' => null,
                    'project_count' => count($this->projects),
                    'lowest_score_user' => null,
                    'lowest_output_user' => null,
                    'most_completed_user' => null,
                    'members' => $this->members,
                    'projects' => $this->projects,
                    'limitation' => null,
                    'kind' => $kind,
                ];
            }
        };
    }
}
