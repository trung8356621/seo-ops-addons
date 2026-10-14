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
    public function outstanding_counts_generated_workflow_items_only(): void
    {
        self::assertTrue(WorkStatsTaskLedger::isOutstanding(SeoProjectTask::STATUS_REVIEWING, SeoProjectTask::TYPE_REWRITE, 9));
        self::assertTrue(WorkStatsTaskLedger::isOutstanding(SeoProjectTask::STATUS_WRITING, SeoProjectTask::TYPE_CREATE, 15));
        self::assertTrue(WorkStatsTaskLedger::isOutstanding(SeoProjectTask::STATUS_WRITING, SeoProjectTask::TYPE_REWRITE, 9));
        self::assertTrue(WorkStatsTaskLedger::isOutstanding(SeoProjectTask::STATUS_FAILED, SeoProjectTask::TYPE_CREATE, 15));
        self::assertTrue(WorkStatsTaskLedger::isOutstanding(SeoProjectTask::STATUS_FAILED, SeoProjectTask::TYPE_REWRITE, 9));
        self::assertTrue(WorkStatsTaskLedger::isOutstanding(SeoProjectTask::STATUS_PROCESSING, SeoProjectTask::TYPE_REWRITE, 9));
        self::assertTrue(WorkStatsTaskLedger::isOutstanding(SeoProjectTask::STATUS_DRAFT, SeoProjectTask::TYPE_CREATE, 15));
        self::assertTrue(WorkStatsTaskLedger::isOutstanding(SeoProjectTask::STATUS_PENDING, SeoProjectTask::TYPE_CREATE, 15));
        self::assertFalse(WorkStatsTaskLedger::isOutstanding(SeoProjectTask::STATUS_PENDING, SeoProjectTask::TYPE_CREATE, 0));
        self::assertFalse(WorkStatsTaskLedger::isOutstanding(SeoProjectTask::STATUS_PENDING, SeoProjectTask::TYPE_REWRITE, 9));
        self::assertFalse(WorkStatsTaskLedger::isOutstanding(SeoProjectTask::STATUS_COMPLETED, SeoProjectTask::TYPE_CREATE, 15));
        self::assertFalse(WorkStatsTaskLedger::isOutstanding(SeoProjectTask::STATUS_ARCHIVED, SeoProjectTask::TYPE_CREATE, 15));
        self::assertFalse(WorkStatsTaskLedger::isOutstanding(SeoProjectTask::STATUS_CANCELLED, SeoProjectTask::TYPE_CREATE, 15));
    }

    #[Test]
    public function project_link_uses_the_content_project_view_route(): void
    {
        $url = ContentProjectAgentLink::url(12);

        self::assertNotNull($url);
        self::assertStringContainsString('/content-projects/12', $url);
    }
}
