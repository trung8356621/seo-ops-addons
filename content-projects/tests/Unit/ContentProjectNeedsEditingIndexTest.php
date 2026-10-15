<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Access\WorkStatsTaskLedger;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectNeedsEditingIndex;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectRecentlyCompletedDefinition;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectNeedsEditingIndexTest extends TestCase
{
    public function test_reviewed_save_clears_listing_detail_and_agent_outstanding_together(): void
    {
        $waiting = $this->row(null);
        $reviewed = $this->row('2026-09-21 09:00:00');

        self::assertTrue(ContentProjectRecentlyCompletedDefinition::matches($waiting));
        self::assertTrue(ContentProjectNeedsEditingIndex::isOutstanding($waiting));
        self::assertTrue(WorkStatsTaskLedger::isOutstanding($waiting));

        self::assertFalse(ContentProjectRecentlyCompletedDefinition::matches($reviewed));
        self::assertFalse(ContentProjectNeedsEditingIndex::isOutstanding($reviewed));
        self::assertFalse(WorkStatsTaskLedger::isOutstanding($reviewed));
    }

    public function test_skipped_generation_is_not_needs_editing(): void
    {
        $row = ContentProjectNeedsEditingIndex::evidenceRow(
            SeoProjectTask::STATUS_COMPLETED,
            ['status' => 'skipped', 'finished_at' => '2026-09-20 15:26:59'],
            null,
            null,
            null,
            null,
        );
        $row['task_status'] = SeoProjectTask::STATUS_COMPLETED;

        self::assertFalse(ContentProjectNeedsEditingIndex::isOutstanding($row));
        self::assertFalse(WorkStatsTaskLedger::isOutstanding($row));
    }

    public function test_content_manager_list_column_reuses_the_index(): void
    {
        $resource = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );
        $list = (string) file_get_contents(
            (string) (new ReflectionClass(ListSeoProjects::class))->getFileName(),
        );

        self::assertStringContainsString('usesContentManagerOpsPresentation()', $resource);
        self::assertStringContainsString('needs_editing_count', $resource);
        self::assertStringContainsString('displayGeneratedCount()', $resource);
        self::assertStringContainsString('ContentProjectNeedsEditingIndex::countsByProject', $list);
        self::assertStringContainsString("getTableSortColumn() === 'active_generated_count'", $list);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(?string $reviewedAt): array
    {
        $row = ContentProjectNeedsEditingIndex::evidenceRow(
            SeoProjectTask::STATUS_COMPLETED,
            ['status' => 'success', 'finished_at' => '2026-09-20 17:22:39'],
            $reviewedAt,
            null,
            null,
            null,
        );
        $row['task_status'] = SeoProjectTask::STATUS_COMPLETED;

        return $row;
    }
}
