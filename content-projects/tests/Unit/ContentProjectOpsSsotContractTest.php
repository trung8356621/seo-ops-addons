<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectApprovedDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFailedOpsDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectInReviewReportingDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsStateClassifier;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectPendingOpsDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectPublishedDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectRecentlyCompletedDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectScheduledDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectStatusBadgePresenter;
use PHPUnit\Framework\TestCase;

/**
 * SSOT: Summary bucket definitions â‰¡ list filter predicates.
 * Reporting chips hide after Approve/Schedule/Publish.
 */
final class ContentProjectOpsSsotContractTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    public static function summaryFilterMatrix(): array
    {
        $baseGen = [
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'is_genuinely_running' => false,
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
            'viewed_generation_completed_at' => null,
            'review_status' => 'draft',
            'lifecycle' => 'review',
            'queue_status' => 'none',
            'is_scheduled' => false,
            'can_generate' => false,
        ];

        return [
            ['draft', array_merge($baseGen, [
                'generation_status' => 'pending',
                'execution_status' => '',
                'generation_completed_at' => null,
                'lifecycle' => 'draft',
                'can_generate' => true,
            ])],
            ['pending', array_merge($baseGen, [
                'generation_status' => 'writing',
                'execution_status' => 'processing',
                'generation_completed_at' => null,
                'lifecycle' => 'draft',
                'is_genuinely_running' => true,
                'can_generate' => false,
            ])],
            ['needs_review', $baseGen],
            ['in_review', array_merge($baseGen, [
                'is_content_manager_reviewed' => true,
                'content_manager_reviewed_at' => '2026-08-01T11:00:00+00:00',
            ])],
            ['approved', array_merge($baseGen, [
                'lifecycle' => 'approved',
                'review_status' => 'approved',
                'is_content_manager_reviewed' => true,
            ])],
            ['scheduled', array_merge($baseGen, [
                'lifecycle' => 'waiting_publish',
                'is_scheduled' => true,
                'scheduled_raw' => '2026-08-02T10:00:00+00:00',
                'queue_status' => 'waiting',
            ])],
            ['published', array_merge($baseGen, [
                'lifecycle' => 'published',
                'queue_status' => 'published',
                'publish_published_at' => '2026-08-02T12:00:00+00:00',
            ])],
            ['failed', array_merge($baseGen, [
                'generation_status' => 'failed',
                'execution_status' => 'failed',
                'lifecycle' => 'failed',
                'generation_completed_at' => null,
            ])],
        ];
    }

    /**
     * @dataProvider summaryFilterMatrix
     *
     * @param  array<string, mixed>  $row
     */
    public function test_summary_definition_matches_list_filter(string $filter, array $row): void
    {
        self::assertTrue(
            ContentProjectOpsStateClassifier::matchesSummaryFilter($row, $filter),
            'Definition must match list filter for '.$filter,
        );

        $counts = ContentProjectOpsStateClassifier::countSummary([$row]);
        $cardKey = match ($filter) {
            'needs_review' => 'recently_completed',
            'in_review' => 'waiting_review',
            'scheduled' => 'waiting_publish',
            default => $filter,
        };
        self::assertSame(1, $counts[$cardKey] ?? 0, 'Summary count must be 1 for '.$filter);
    }

    public function test_reporting_clears_after_approve_schedule_publish(): void
    {
        $stamped = [
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
            'is_content_manager_reviewed' => true,
            'content_manager_reviewed_at' => '2026-08-01T11:00:00+00:00',
            'review_status' => 'draft',
            'lifecycle' => 'review',
            'queue_status' => 'none',
        ];
        self::assertTrue(ContentProjectInReviewReportingDefinition::matches($stamped));
        self::assertTrue(ContentProjectOpsStateClassifier::classify($stamped)['show_reporting_chip']);

        foreach ([
            ['lifecycle' => 'approved', 'review_status' => 'approved'],
            ['lifecycle' => 'waiting_publish', 'is_scheduled' => true, 'queue_status' => 'waiting'],
            ['lifecycle' => 'published', 'queue_status' => 'published', 'publish_published_at' => '2026-08-02T00:00:00+00:00'],
        ] as $overlay) {
            $row = array_merge($stamped, $overlay);
            self::assertFalse(ContentProjectRecentlyCompletedDefinition::matches($row));
            self::assertFalse(ContentProjectInReviewReportingDefinition::matches($row));
            self::assertFalse(ContentProjectOpsStateClassifier::classify($row)['show_reporting_chip']);
        }
    }

    public function test_published_ignores_article_status_alone(): void
    {
        self::assertFalse(ContentProjectPublishedDefinition::matches([
            'lifecycle' => 'review',
            'queue_status' => 'none',
            'article_status' => 'published',
        ]));
        self::assertTrue(ContentProjectPublishedDefinition::matches([
            'lifecycle' => 'published',
            'queue_status' => 'none',
        ]));
        self::assertTrue(ContentProjectPublishedDefinition::matches([
            'lifecycle' => 'review',
            'queue_status' => 'published',
        ]));
    }

    public function test_generation_badge_never_needs_review_label(): void
    {
        $badge = ContentProjectStatusBadgePresenter::generation('completed', 'success');
        self::assertSame('success', $badge['key']);
        self::assertStringNotContainsString('Needs Review', $badge['label']);
        self::assertStringNotContainsString('In Review', $badge['label']);
    }

    public function test_workflow_badge_never_reporting(): void
    {
        $w = ContentProjectStatusBadgePresenter::workflow('scheduled');
        self::assertNotNull($w);
        self::assertSame('scheduled', $w['key']);
        self::assertNull(ContentProjectStatusBadgePresenter::workflow('draft'));
        self::assertNull(ContentProjectStatusBadgePresenter::workflow('pending'));
        self::assertNull(ContentProjectStatusBadgePresenter::reporting(null));
        $nr = ContentProjectStatusBadgePresenter::reporting('needs_review');
        self::assertNotNull($nr);
        self::assertSame('needs_review', $nr['key']);
    }

    public function test_mutually_exclusive_summary_buckets(): void
    {
        $row = [
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
            'is_content_manager_reviewed' => true,
            'lifecycle' => 'waiting_publish',
            'is_scheduled' => true,
            'queue_status' => 'waiting',
            'review_status' => 'draft',
        ];
        $c = ContentProjectOpsStateClassifier::classify($row);
        self::assertSame(ContentProjectOpsStateClassifier::BUCKET_SCHEDULED, $c['summary_bucket']);
        self::assertFalse($c['is_needs_review']);
        self::assertFalse($c['is_in_review_reporting']);
        self::assertTrue($c['is_scheduled_canonical']);
    }

    public function test_counter_deltas_derive_from_classifier(): void
    {
        self::assertSame(
            ['needs_review' => -1, 'scheduled' => 1],
            ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_NEEDS_REVIEW,
                ContentProjectOpsStateClassifier::BUCKET_SCHEDULED,
            ),
        );
        self::assertSame(
            ['published' => -1, 'scheduled' => 1],
            ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_PUBLISHED,
                ContentProjectOpsStateClassifier::BUCKET_SCHEDULED,
            ),
        );
        self::assertSame(
            ['draft' => -1, 'pending' => 1],
            ContentProjectOpsStateClassifier::deltaBetween(
                ContentProjectOpsStateClassifier::BUCKET_DRAFT,
                ContentProjectOpsStateClassifier::BUCKET_PENDING,
            ),
        );
    }

    public function test_definition_classes_exist_for_each_card(): void
    {
        self::assertTrue(class_exists(ContentProjectPendingOpsDefinition::class));
        self::assertTrue(class_exists(\Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectDraftOpsDefinition::class));
        self::assertTrue(class_exists(ContentProjectRecentlyCompletedDefinition::class));
        self::assertTrue(class_exists(ContentProjectInReviewReportingDefinition::class));
        self::assertTrue(class_exists(ContentProjectApprovedDefinition::class));
        self::assertTrue(class_exists(ContentProjectScheduledDefinition::class));
        self::assertTrue(class_exists(ContentProjectPublishedDefinition::class));
        self::assertTrue(class_exists(ContentProjectFailedOpsDefinition::class));
    }

    public function test_ops_ui_separates_generation_workflow_reporting(): void
    {
        $view = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php'),
        );
        // Column headers + workflow badge rendering live in the shared items-list component.
        $itemsList = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-items-list.blade.php'),
        );
        self::assertStringContainsString('ops_col_workflow', $itemsList);
        self::assertStringContainsString('ops_col_generation', $itemsList);
        self::assertStringContainsString('workflow_badge', $itemsList);
        self::assertStringContainsString("'card' => 'normal'", $view);
        self::assertStringNotContainsString("'card' => 'total'", $view);
        self::assertStringNotContainsString("'card' => 'approved'", $view);
        self::assertStringNotContainsString("'card' => 'scheduled'", $view);
        self::assertStringNotContainsString("'card' => 'published'", $view);
        self::assertStringContainsString('ops_failure_all', $view);
        self::assertStringContainsString('applyFailureTypeFilter', $view);

        $page = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProject.php',
        );
        self::assertStringContainsString('ops_total_badge', $page);
        self::assertStringContainsString('workflowFilter', $page);

        $toolbar = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-filter-toolbar.blade.php'),
        );
        self::assertStringContainsString('workflowFilter', $toolbar);
        self::assertStringContainsString('ops_workflow_all', $toolbar);
        self::assertStringNotContainsString('value="approved"', $toolbar);
        preg_match_all('/wire:model\.live="generationFilter"[\s\S]*?<\/x-select>/', $toolbar, $genSelects);
        self::assertNotSame([], $genSelects[0]);
        foreach ($genSelects[0] as $block) {
            self::assertStringNotContainsString('recently_completed', $block);
            self::assertStringNotContainsString('in_review_reporting', $block);
        }
        self::assertMatchesRegularExpression(
            '/wire:model\.live="workflowFilter"[\s\S]*?value="recently_completed"/',
            $toolbar,
        );
    }

    public function test_failure_type_mapper_legacy_reason(): void
    {
        self::assertSame(
            'timeout',
            \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFailureTypeMapper::resolve([
                'message' => 'Request timed out after 60s',
            ]),
        );
        self::assertSame(
            'wordpress',
            \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFailureTypeMapper::resolve([
                'current_error_source' => 'publish',
                'message' => 'WP REST error',
            ]),
        );
        self::assertSame(
            'prompt',
            \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFailureTypeMapper::resolve([
                'failure_type' => 'prompt',
            ]),
        );
    }
}
