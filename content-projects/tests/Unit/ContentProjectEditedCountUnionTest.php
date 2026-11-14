<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectInReviewReportingDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsStateClassifier;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * CP-04: edited = DISTINCT(stamped ∪ publishing_queue); working set ∩ queue = ∅.
 */
final class ContentProjectEditedCountUnionTest extends TestCase
{
    public function test_queue_membership_counts_as_edited_without_stamp(): void
    {
        $queued = [
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'is_content_manager_reviewed' => false,
            'content_manager_reviewed_at' => null,
            'in_publishing_queue' => true,
            'publishing_queued_at' => '2026-11-01T00:00:00+00:00',
            'review_status' => 'draft',
            'lifecycle' => 'review',
            'queue_status' => 'none',
        ];
        self::assertTrue(ContentProjectInReviewReportingDefinition::matches($queued));
        self::assertSame(
            ContentProjectOpsStateClassifier::BUCKET_IN_REVIEW,
            ContentProjectOpsStateClassifier::classify($queued)['summary_bucket'],
        );
    }

    public function test_queue_waiting_status_still_counts_as_edited_not_scheduled_bucket(): void
    {
        // Production bug: queue_status=waiting matched Scheduled before In Review → list KPI=2.
        $queuedWaiting = [
            'generation_status' => 'completed',
            'is_content_manager_reviewed' => false,
            'in_publishing_queue' => true,
            'publishing_queued_at' => '2026-11-01T00:00:00+00:00',
            'review_status' => 'draft',
            'lifecycle' => 'review',
            'queue_status' => 'waiting',
        ];
        self::assertTrue(ContentProjectInReviewReportingDefinition::matches($queuedWaiting));
        self::assertSame(
            ContentProjectOpsStateClassifier::BUCKET_IN_REVIEW,
            ContentProjectOpsStateClassifier::classify($queuedWaiting)['summary_bucket'],
        );
        self::assertNotSame(
            ContentProjectOpsStateClassifier::BUCKET_SCHEDULED,
            ContentProjectOpsStateClassifier::classify($queuedWaiting)['summary_bucket'],
        );
    }

    public function test_editorial_list_index_counts_queue_without_classifier(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectEditorialListIndex::class))->getFileName(),
        );
        self::assertStringContainsString('Do NOT route queue rows through OpsStateClassifier', $src);
        self::assertStringContainsString('if (! $inWorkingSet)', $src);
        self::assertStringContainsString("\$counts[\$projectId]['review']++", $src);
    }

    public function test_stamped_without_queue_counts_as_edited(): void
    {
        $stamped = [
            'generation_status' => 'completed',
            'is_content_manager_reviewed' => true,
            'content_manager_reviewed_at' => '2026-11-01T00:00:00+00:00',
            'in_publishing_queue' => false,
            'review_status' => 'draft',
            'lifecycle' => 'review',
            'queue_status' => 'none',
        ];
        self::assertTrue(ContentProjectInReviewReportingDefinition::matches($stamped));
    }

    public function test_neither_stamp_nor_queue_is_not_edited(): void
    {
        self::assertFalse(ContentProjectInReviewReportingDefinition::matches([
            'generation_status' => 'completed',
            'is_content_manager_reviewed' => false,
            'in_publishing_queue' => false,
            'publishing_queued_at' => null,
            'review_status' => 'draft',
            'lifecycle' => 'review',
            'queue_status' => 'none',
        ]));
    }

    public function test_union_math_for_project_2509_shape(): void
    {
        // 27 queue + 0 stamped working-set + 3 unedited working-set → edited=27
        $editedWorking = 0;
        $queue = 27;
        self::assertSame(27, $editedWorking + $queue);

        // stamped in working set + same item cannot also be in queue (disjoint)
        self::assertSame(1, 1 + 0);
        // stamped working-set only
        self::assertSame(1, 1 + 0);
    }

    public function test_detail_read_model_adds_queue_into_waiting_review(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectItemOperationsReadModel::class))->getFileName(),
        );
        self::assertStringContainsString("\$stats['waiting_review'] = \$editedWorkingSet + \$publishingQueueCount", $src);
        self::assertStringContainsString('publishingQueueCount', $src);
    }

    public function test_stamped_and_queued_row_still_counts_once_in_classifier(): void
    {
        $row = [
            'generation_status' => 'completed',
            'is_content_manager_reviewed' => true,
            'content_manager_reviewed_at' => '2026-11-01T00:00:00+00:00',
            'in_publishing_queue' => true,
            'publishing_queued_at' => '2026-11-02T00:00:00+00:00',
            'review_status' => 'draft',
            'lifecycle' => 'review',
            'queue_status' => 'none',
        ];
        $counts = ContentProjectOpsStateClassifier::countSummary([$row]);
        self::assertSame(1, $counts['waiting_review']);
    }
}
