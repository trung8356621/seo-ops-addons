<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Access\ContentProjectAgentLink;
use Omnichannel\Addons\AgentRuntime\Access\WorkStatsTaskLedger;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkStatsTaskLedgerTest extends TestCase
{
    #[Test]
    public function generated_article_waiting_for_content_manager_edit_is_outstanding(): void
    {
        $row = WorkStatsTaskLedger::evidenceRow(
            SeoProjectTask::STATUS_COMPLETED,
            ['status' => 'success', 'finished_at' => '2026-09-20 17:22:39'],
            null,
            null,
            null,
            null,
        );
        $row['task_status'] = SeoProjectTask::STATUS_COMPLETED;

        self::assertTrue(WorkStatsTaskLedger::isOutstanding($row));
    }

    #[Test]
    public function published_or_skipped_or_ungenerated_work_is_not_outstanding(): void
    {
        $published = WorkStatsTaskLedger::evidenceRow(
            SeoProjectTask::STATUS_COMPLETED,
            ['status' => 'success', 'finished_at' => '2026-09-20 15:32:36'],
            null,
            null,
            'publish',
            null,
        );
        $skipped = WorkStatsTaskLedger::evidenceRow(
            SeoProjectTask::STATUS_COMPLETED,
            ['status' => 'skipped', 'finished_at' => '2026-09-20 15:33:00'],
            null,
            null,
            null,
            null,
        );
        $plan = WorkStatsTaskLedger::evidenceRow(SeoProjectTask::STATUS_PENDING, null, null, null, null, null);
        $rewritePlan = WorkStatsTaskLedger::evidenceRow(
            SeoProjectTask::STATUS_PENDING,
            null,
            null,
            null,
            null,
            null,
        );
        $reviewed = WorkStatsTaskLedger::evidenceRow(
            SeoProjectTask::STATUS_COMPLETED,
            ['status' => 'success', 'finished_at' => '2026-09-20 17:22:39'],
            '2026-09-21 09:00:00',
            null,
            null,
            null,
        );
        $archived = WorkStatsTaskLedger::evidenceRow(
            SeoProjectTask::STATUS_ARCHIVED,
            ['status' => 'success', 'finished_at' => '2026-09-20 17:22:39'],
            null,
            null,
            null,
            null,
        );
        $archived['task_status'] = SeoProjectTask::STATUS_ARCHIVED;
        $cancelled = ['task_status' => SeoProjectTask::STATUS_CANCELLED, 'generation_status' => 'completed', 'execution_status' => 'success', 'generation_completed_at' => '2026-09-20 17:22:39'];

        self::assertFalse(WorkStatsTaskLedger::isOutstanding($published));
        self::assertFalse(WorkStatsTaskLedger::isOutstanding($skipped));
        self::assertFalse(WorkStatsTaskLedger::isOutstanding($plan));
        self::assertFalse(WorkStatsTaskLedger::isOutstanding($rewritePlan));
        self::assertFalse(WorkStatsTaskLedger::isOutstanding($reviewed));
        self::assertFalse(WorkStatsTaskLedger::isOutstanding($archived));
        self::assertFalse(WorkStatsTaskLedger::isOutstanding($cancelled));
    }

    #[Test]
    public function project_link_uses_the_content_project_view_route(): void
    {
        $url = ContentProjectAgentLink::url(12);

        self::assertNotNull($url);
        self::assertStringContainsString('/content-projects/12', $url);
    }
}
