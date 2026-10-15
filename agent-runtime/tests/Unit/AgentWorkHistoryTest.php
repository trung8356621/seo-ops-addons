<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Access\AgentActorAccessContext;
use Omnichannel\Addons\AgentRuntime\Access\AgentWorkHistoryReader;
use Omnichannel\Addons\AgentRuntime\Access\AgentWorkHistoryService;
use Omnichannel\Addons\AgentRuntime\Access\WorkHistoryRecord;
use Omnichannel\Addons\AgentRuntime\Routing\LocalAgentToolRouter;
use Omnichannel\Addons\AgentRuntime\Routing\SemanticRoutingConfig;
use Omnichannel\Addons\AgentRuntime\Routing\WeightedRouteEvaluator;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AgentWorkHistoryTest extends TestCase
{
    #[Test]
    public function history_question_routes_to_read_only_history_and_leaves_remaining_work_alone(): void
    {
        $evaluator = $this->createMock(WeightedRouteEvaluator::class);
        $evaluator->expects($this->never())->method('evaluate');
        $router = new LocalAgentToolRouter($evaluator, new SemanticRoutingConfig());

        $history = $router->route('Cho tôi xem toàn bộ lịch sử công việc của tôi.');
        self::assertSame('work.history', $history->capability);
        self::assertSame('READ', $history->intentFamily);
        self::assertFalse($history->answerModelRequired);

        $remaining = $router->route('Tôi còn bao nhiêu bài viết chưa hoàn thành?');
        self::assertSame('work.stats', $remaining->capability);
    }

    #[Test]
    public function history_page_is_bounded_and_marks_only_current_outstanding_rows(): void
    {
        self::assertSame(1, WorkHistoryRecord::pageNumber('Cho tôi xem toàn bộ lịch sử công việc của tôi.'));
        self::assertSame(2, WorkHistoryRecord::pageNumber('Cho tôi xem lịch sử công việc trang 2'));
        self::assertTrue(WorkHistoryRecord::enteredWorkflow(SeoProjectTask::STATUS_COMPLETED, SeoProjectTask::TYPE_CREATE, 13921));
        self::assertTrue(WorkHistoryRecord::enteredWorkflow(SeoProjectTask::STATUS_WRITING, SeoProjectTask::TYPE_REWRITE, 4283));
        self::assertFalse(WorkHistoryRecord::enteredWorkflow(SeoProjectTask::STATUS_PENDING, SeoProjectTask::TYPE_CREATE, 0));
        self::assertFalse(WorkHistoryRecord::enteredWorkflow(SeoProjectTask::STATUS_PENDING, SeoProjectTask::TYPE_REWRITE, 4283));
        self::assertFalse(WorkHistoryRecord::enteredWorkflow(SeoProjectTask::STATUS_CANCELLED, SeoProjectTask::TYPE_CREATE, 15));
        self::assertSame('outstanding', WorkHistoryRecord::classification(SeoProjectTask::STATUS_COMPLETED, SeoProjectTask::TYPE_CREATE, 13921, false, false, true));
        self::assertSame('archived', WorkHistoryRecord::classification(SeoProjectTask::STATUS_COMPLETED, SeoProjectTask::TYPE_REWRITE, 4283, false, true, true));
        self::assertSame('planned', WorkHistoryRecord::classification(SeoProjectTask::STATUS_PENDING, SeoProjectTask::TYPE_CREATE, 0, false, false, false));
        self::assertTrue(SeoProjectTask::isUngeneratedPlanStatus(SeoProjectTask::STATUS_PENDING, SeoProjectTask::TYPE_CREATE, 0));
        self::assertTrue(SeoProjectTask::isUngeneratedPlanStatus(SeoProjectTask::STATUS_PENDING, SeoProjectTask::TYPE_REWRITE, 4283));
        self::assertFalse(SeoProjectTask::isUngeneratedPlanStatus(SeoProjectTask::STATUS_COMPLETED, SeoProjectTask::TYPE_CREATE, 13921));
        self::assertFalse(SeoProjectTask::isUngeneratedPlanStatus(SeoProjectTask::STATUS_COMPLETED, SeoProjectTask::TYPE_REWRITE, 4283));
        self::assertFalse(SeoProjectTask::isUngeneratedPlanStatus(SeoProjectTask::STATUS_ARCHIVED, SeoProjectTask::TYPE_CREATE, 15));

        $context = new AgentActorAccessContext(9, SeoAccessControl::ROLE_CONTENT_MANAGER, [501], [4], false, false);
        $service = new AgentWorkHistoryService(new class implements AgentWorkHistoryReader
        {
            public function inspect(AgentActorAccessContext $context, int $page, int $perPage): array
            {
                TestCase::assertSame(9, $context->userId);
                TestCase::assertSame(2, $page);
                TestCase::assertSame(WorkHistoryRecord::PER_PAGE, $perPage);

                return [
                    'planned' => 20,
                    'entered_workflow' => 3,
                    'completed' => 3,
                    'outstanding' => 1,
                    'archived' => 2,
                    'total' => 25,
                    'page' => $page,
                    'per_page' => $perPage,
                    'rows' => [[
                        'project_id' => 501,
                        'project_name' => 'project 8/2026',
                        'month' => '08/2026',
                        'task_id' => 9354,
                        'article_id' => 13921,
                        'type' => 'create',
                        'status' => 'completed',
                        'classification' => 'outstanding',
                        'outstanding' => true,
                    ]],
                ];
            }
        });
        $response = $service->answer($context, 'Cho tôi xem lịch sử công việc trang 2');

        self::assertStringContainsString('Kế hoạch: 20', $response->message);
        self::assertStringContainsString('Đã vào quy trình Content Manager: 3', $response->message);
        self::assertStringContainsString('Đã hoàn thành: 3', $response->message);
        self::assertStringContainsString('Đang outstanding: 1', $response->message);
        self::assertStringContainsString('Lưu trữ: 2', $response->message);
        self::assertStringContainsString('Trang 2/2, hiển thị 1/25 task.', $response->message);
        self::assertSame('table', $response->blocks[1]['type']);
        self::assertSame(9354, $response->blocks[1]['rows'][0]['task_id']);
        self::assertSame(13921, $response->blocks[1]['rows'][0]['article_id']);
        self::assertSame('Có', $response->blocks[1]['rows'][0]['outstanding']);
        self::assertSame('project 8/2026', $response->blocks[1]['rows'][0]['project']['label']);
        self::assertStringContainsString('/content-projects/501', (string) $response->blocks[1]['rows'][0]['project']['href']);
        self::assertSame('work.history', $response->sources[0]['name']);
    }
}
