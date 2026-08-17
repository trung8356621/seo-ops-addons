<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectInReviewReportingDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsCounterTransitionMap;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectRecentlyCompletedDefinition;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Content Project role/workflow contracts: planner-equivalent capability,
 * Needs Review vs In Review reporting, Schedule without Approved gate.
 */
final class ContentProjectRoleWorkflowContractTest extends TestCase
{
    public function test_planner_equivalent_capability_method_exists(): void
    {
        $ref = new ReflectionClass(SeoAccessControl::class);
        self::assertTrue($ref->hasMethod('canManageContentProjectWorkflow'));
        $m = new ReflectionMethod(SeoAccessControl::class, 'canManageContentProjectWorkflow');
        self::assertTrue($m->isPublic());
        self::assertTrue($m->isStatic());
    }

    public function test_content_project_run_requires_planner_equivalent_not_assigned_cm(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAccessControl::class))->getFileName(),
        );
        $runPos = strpos($src, 'function canAccessContentProjectRun');
        self::assertNotFalse($runPos);
        $snippet = substr($src, $runPos, 450);
        self::assertStringContainsString('canManageContentProjectWorkflow', $snippet);
        self::assertStringNotContainsString('isContentManager()', $snippet);
        self::assertStringNotContainsString('project->user_id', $snippet);

        $retryPos = strpos($src, 'function canRetryProjectRunItem');
        self::assertNotFalse($retryPos);
        $retrySnippet = substr($src, $retryPos, 250);
        self::assertStringContainsString('canManageContentProjectWorkflow', $retrySnippet);
        self::assertStringNotContainsString('isContentManager()', $retrySnippet);
    }

    public function test_capability_does_not_open_prompt_or_system_settings(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAccessControl::class))->getFileName(),
        );
        $pos = strpos($src, 'function canManageContentProjectWorkflow');
        self::assertNotFalse($pos);
        $snippet = substr($src, $pos, 350);
        self::assertStringContainsString('canMutateInSeoPanel', $snippet);
        self::assertStringContainsString('canAccessPlannerFeatures', $snippet);
        self::assertStringNotContainsString('Prompt', $snippet);
        self::assertStringNotContainsString('canAccessManagerFeatures', $snippet);
    }

    public function test_needs_review_excludes_cm_stamp_and_legacy_handoff(): void
    {
        self::assertFalse(ContentProjectRecentlyCompletedDefinition::matches([
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'is_genuinely_running' => false,
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
            'viewed_generation_completed_at' => null,
            'review_status' => 'draft',
            'is_content_manager_reviewed' => true,
        ]));

        self::assertFalse(ContentProjectRecentlyCompletedDefinition::matches([
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'is_genuinely_running' => false,
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
            'viewed_generation_completed_at' => null,
            'review_status' => 'pending_review',
        ]));

        self::assertFalse(ContentProjectRecentlyCompletedDefinition::matches([
            'generation_status' => 'reviewing',
            'execution_status' => 'success',
            'is_genuinely_running' => false,
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
            'viewed_generation_completed_at' => null,
            'review_status' => 'draft',
        ]));

        self::assertTrue(ContentProjectRecentlyCompletedDefinition::matches([
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'is_genuinely_running' => false,
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
            'viewed_generation_completed_at' => null,
            'review_status' => 'draft',
        ]));
    }

    public function test_in_review_reporting_matches_stamp_only(): void
    {
        self::assertTrue(ContentProjectInReviewReportingDefinition::matches([
            'is_content_manager_reviewed' => true,
            'lifecycle' => 'review',
            'review_status' => 'draft',
            'is_scheduled' => false,
            'queue_status' => 'none',
        ]));

        self::assertFalse(ContentProjectInReviewReportingDefinition::matches([
            'is_content_manager_reviewed' => true,
            'lifecycle' => 'approved',
            'review_status' => 'approved',
        ]));

        self::assertFalse(ContentProjectInReviewReportingDefinition::matches([
            'is_content_manager_reviewed' => false,
            'lifecycle' => 'review',
            'review_status' => 'draft',
            'generation_status' => 'completed',
        ]));
    }

    public function test_counter_map_atomic_handoff_approve_and_schedule_paths(): void
    {
        self::assertSame(
            ['needs_review' => -1, 'review' => 1],
            ContentProjectOpsCounterTransitionMap::deltas(
                ContentProjectOpsCounterTransitionMap::ACTION_CONTENT_MANAGER_HANDOFF,
            ),
        );
        self::assertSame(
            ['needs_review' => -1, 'approved' => 1],
            ContentProjectOpsCounterTransitionMap::deltas(
                ContentProjectOpsCounterTransitionMap::ACTION_APPROVE_FROM_NEEDS_REVIEW,
            ),
        );
        self::assertSame(
            ['review' => -1, 'approved' => 1],
            ContentProjectOpsCounterTransitionMap::deltas(
                ContentProjectOpsCounterTransitionMap::ACTION_APPROVE,
            ),
        );
        self::assertSame(
            ['approved' => 1],
            ContentProjectOpsCounterTransitionMap::deltas(
                ContentProjectOpsCounterTransitionMap::ACTION_APPROVE_SELF_EDIT,
            ),
        );
        self::assertSame(
            ['approved' => -1, 'scheduled' => 1],
            ContentProjectOpsCounterTransitionMap::deltas(
                ContentProjectOpsCounterTransitionMap::ACTION_SCHEDULE,
            ),
        );
        self::assertSame(
            ['needs_review' => -1, 'scheduled' => 1],
            ContentProjectOpsCounterTransitionMap::deltas(
                ContentProjectOpsCounterTransitionMap::ACTION_SCHEDULE_FROM_NEEDS_REVIEW,
            ),
        );
        self::assertSame(
            ['review' => -1, 'scheduled' => 1],
            ContentProjectOpsCounterTransitionMap::deltas(
                ContentProjectOpsCounterTransitionMap::ACTION_SCHEDULE_FROM_REVIEW,
            ),
        );
    }

    public function test_approve_action_for_row_resolution(): void
    {
        self::assertSame(
            ContentProjectOpsCounterTransitionMap::ACTION_APPROVE,
            ContentProjectOpsCounterTransitionMap::approveActionForRow([
                'is_content_manager_reviewed' => true,
                'is_recently_completed' => true,
            ]),
        );
        self::assertSame(
            ContentProjectOpsCounterTransitionMap::ACTION_APPROVE_FROM_NEEDS_REVIEW,
            ContentProjectOpsCounterTransitionMap::approveActionForRow([
                'review_status' => 'draft',
                'is_recently_completed' => true,
            ]),
        );
        self::assertSame(
            ContentProjectOpsCounterTransitionMap::ACTION_APPROVE_SELF_EDIT,
            ContentProjectOpsCounterTransitionMap::approveActionForRow([
                'review_status' => 'draft',
                'is_recently_completed' => false,
            ]),
        );
    }

    public function test_schedule_action_for_row_resolution(): void
    {
        self::assertSame(
            ContentProjectOpsCounterTransitionMap::ACTION_SCHEDULE,
            ContentProjectOpsCounterTransitionMap::scheduleActionForRow([
                'review_status' => 'approved',
                'is_content_manager_reviewed' => true,
            ]),
        );
        self::assertSame(
            ContentProjectOpsCounterTransitionMap::ACTION_SCHEDULE_FROM_REVIEW,
            ContentProjectOpsCounterTransitionMap::scheduleActionForRow([
                'lifecycle' => 'review',
                'is_content_manager_reviewed' => true,
            ]),
        );
        self::assertSame(
            ContentProjectOpsCounterTransitionMap::ACTION_SCHEDULE_FROM_NEEDS_REVIEW,
            ContentProjectOpsCounterTransitionMap::scheduleActionForRow([
                'lifecycle' => 'review',
                'is_recently_completed' => true,
            ]),
        );
    }

    public function test_presenter_never_schedules_published(): void
    {
        $flags = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'published',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'success'],
            'can_generate' => false,
            'can_regen' => false,
            'is_improve' => false,
            'article_id' => 9,
            'article_edit_url' => '/seo/articles/1/edit',
            'is_scheduled' => false,
            'has_unpublished_changes' => true,
            'generation_status' => 'completed',
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
            'execution_status' => 'success',
        ]);

        self::assertFalse($flags['schedule']);
        self::assertFalse($flags['publish_now']);
        self::assertTrue($flags['send_to_publishing_queue']);
    }

    public function test_presenter_schedule_from_review_and_approved(): void
    {
        // Schedule CTA left Content Project â€” Publishing Queue owns it.
        // CP offers Send to Publishing Queue for content-ready items.
        $approved = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'approved',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'success'],
            'can_generate' => false,
            'article_id' => 9,
            'article_edit_url' => '/x',
            'generation_status' => 'completed',
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
            'execution_status' => 'success',
        ]);
        self::assertFalse($approved['schedule']);
        self::assertTrue($approved['send_to_publishing_queue']);

        $review = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'review',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'success'],
            'can_generate' => false,
            'article_id' => 9,
            'article_edit_url' => '/x',
            'generation_status' => 'completed',
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
            'execution_status' => 'success',
        ]);
        self::assertFalse($review['schedule']);
        self::assertTrue($review['send_to_publishing_queue']);
        self::assertTrue($review['approve']);
    }

    public function test_schedule_guard_allows_review_lifecycle(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Support/ContentProject/ContentProjectItemActionGuard.php',
        );
        $pos = strpos($src, 'function queueScheduleEligible');
        self::assertNotFalse($pos);
        $snippet = substr($src, $pos, 900);
        self::assertStringContainsString('ContentProjectLifecyclePhase::Review', $snippet);
        self::assertStringContainsString('ContentProjectLifecyclePhase::Approved', $snippet);
        self::assertStringContainsString('ContentProjectLifecyclePhase::WaitingPublish', $snippet);
    }

    public function test_article_looks_published_ignores_editor_status(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Support/ContentProject/ContentProjectItemStateResolver.php',
        );
        $pos = strpos($src, 'function articleLooksPublished');
        self::assertNotFalse($pos);
        $snippet = substr($src, $pos, 280);
        self::assertStringContainsString('return false', $snippet);
        self::assertStringNotContainsString("'published'", $snippet);
    }

    public function test_waiting_review_sql_uses_reporting_stamp(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectDashboardStatsService.php',
        );
        self::assertStringContainsString('content_manager_reviewed_at', $src);
        self::assertStringContainsString("a.review_status = 'pending_review'", $src);
        self::assertStringContainsString("t.status = 'reviewing'", $src);
        self::assertStringNotContainsString(
            "(t.status = 'completed' AND COALESCE(a.review_status,'') != 'approved')",
            $src,
        );
    }

    public function test_handoff_service_stamps_reporting_not_lifecycle(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectContentManagerHandoffService.php',
        );
        self::assertStringContainsString('ORIGIN_ARTICLE_EDITOR', $src);
        self::assertStringContainsString('canSubmitArticleReview', $src);
        self::assertStringContainsString('content_manager_reviewed_at', $src);
        self::assertStringContainsString('content_manager_reviewed_by', $src);
        self::assertStringNotContainsString('SubmitReview', $src);
        self::assertStringNotContainsString('ArticleReviewService', $src);
        // Legacy ready-check may allow STATUS_REVIEWING; must not write task status to reviewing.
        self::assertStringNotContainsString("update(['status' => SeoProjectTask::STATUS_REVIEWING", $src);
        self::assertStringNotContainsString("'status' => SeoProjectTask::STATUS_REVIEWING", $src);
        self::assertStringNotContainsString("role === 'content_manager'", $src);
        self::assertStringNotContainsString('staff', strtolower($src));
    }

    public function test_ops_ui_tab_order_failed_last_and_labels(): void
    {
        $view = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php'),
        );
        $failedPos = strpos($view, "'card' => 'failed'");
        $publishedPos = strpos($view, "'card' => 'published'");
        $scheduledPos = strpos($view, "'card' => 'scheduled'");
        $reviewPos = strpos($view, "'card' => 'review'");
        $needsPos = strpos($view, "'card' => 'recently_completed'");
        self::assertNotFalse($failedPos);
        self::assertFalse($publishedPos);
        self::assertFalse($scheduledPos);
        self::assertNotFalse($reviewPos);
        self::assertNotFalse($needsPos);
        // PHPUnit: assertLessThan($expected, $actual) â‡’ $actual < $expected
        self::assertLessThan($reviewPos, $needsPos); // needs before review
        self::assertLessThan($failedPos, $reviewPos); // review before failed (planner cards)
        self::assertStringContainsString('ops_needs_review_hint', $view);
        self::assertStringContainsString('ops_in_review_hint', $view);
        self::assertStringNotContainsString('AI Inbox', $view);
    }

    public function test_lang_labels_reporting_semantics(): void
    {
        $en = (string) file_get_contents(LegacyAddonPath::resolve('lang/en/filament.php'));
        $vi = (string) file_get_contents(LegacyAddonPath::resolve('lang/vi/filament.php'));
        self::assertStringContainsString("'ops_needs_review' => 'Needs Review'", $en);
        self::assertStringContainsString("'ops_in_review' => 'In Review'", $en);
        self::assertStringContainsString('reporting badge only', $en);
        self::assertStringContainsString("'ops_needs_review' => 'Cáº§n biÃªn táº­p'", $vi);
        self::assertStringContainsString("'ops_in_review' => 'ÄÃ£ biÃªn táº­p'", $vi);
        self::assertStringContainsString('reporting badge', $vi);
        self::assertStringNotContainsString('waiting for Planner approval', $en);
    }
}
