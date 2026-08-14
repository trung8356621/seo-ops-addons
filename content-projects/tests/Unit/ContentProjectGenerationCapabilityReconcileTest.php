<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExistingArticleReconcileResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExistingArticleReconciler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationCapabilityResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryDecision;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Capability-driven Resume/Run again + safe Existing Article reconciliation.
 */
final class ContentProjectGenerationCapabilityReconcileTest extends TestCase
{
    public function test_resume_visible_only_when_capability_says_resume(): void
    {
        $resume = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'failed',
            'queue_status' => 'none',
            'generation_status' => 'failed',
            'generation_badge' => ['key' => 'failed'],
            'can_generate' => true,
            'can_regen' => true,
            'is_improve' => false,
            'is_generation_stale' => false,
            'is_genuinely_running' => false,
            'has_resumable_checkpoint' => true,
            'generation_recovery_action' => ContentProjectGenerationRecoveryDecision::ACTION_RESUME,
            'article_edit_url' => '/seo/articles/10/edit',
        ]);

        self::assertTrue($resume['resume_generation']);
        self::assertFalse($resume['create_or_rerun']);
        self::assertFalse($resume['run_again']);
    }

    public function test_rerun_only_when_resume_not_executable(): void
    {
        $rerun = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'failed',
            'queue_status' => 'none',
            'generation_status' => 'failed',
            'generation_badge' => ['key' => 'failed'],
            'can_generate' => true,
            'can_regen' => true,
            'is_improve' => false,
            'is_generation_stale' => false,
            'is_genuinely_running' => false,
            'has_resumable_checkpoint' => false,
            'generation_recovery_action' => ContentProjectGenerationRecoveryDecision::ACTION_RERUN,
            'article_edit_url' => '/seo/articles/10/edit',
        ]);

        self::assertFalse($rerun['resume_generation']);
        self::assertTrue($rerun['create_or_rerun']);
        self::assertSame('rerun', $rerun['create_or_rerun_label']);
    }

    public function test_active_hides_resume_and_rerun(): void
    {
        $actions = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'generating',
            'generation_status' => 'writing',
            'generation_badge' => ['key' => 'running'],
            'is_genuinely_running' => true,
            'has_resumable_checkpoint' => true,
            'generation_recovery_action' => ContentProjectGenerationRecoveryDecision::ACTION_ACTIVE,
            'article_edit_url' => '/seo/articles/10/edit',
        ]);

        self::assertFalse($actions['resume_generation']);
        self::assertFalse($actions['create_or_rerun']);
        self::assertTrue($actions['stop_generation']);
    }

    public function test_missing_existing_article_shows_select_only(): void
    {
        $actions = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'failed',
            'generation_status' => 'failed',
            'generation_badge' => ['key' => 'failed'],
            'can_generate' => true,
            'can_regen' => false,
            'is_genuinely_running' => false,
            'has_resumable_checkpoint' => true,
            'generation_recovery_action' => ContentProjectGenerationRecoveryDecision::ACTION_SELECT_EXISTING_ARTICLE,
            'article_edit_url' => null,
        ]);

        self::assertFalse($actions['resume_generation']);
        self::assertFalse($actions['create_or_rerun']);
        self::assertTrue($actions['select_existing_article']);
    }

    public function test_snapshot_decision_select_when_article_missing(): void
    {
        $decision = ContentProjectGenerationCapabilityResolver::decideFromSnapshot([
            'task_id' => 378,
            'requires_existing_article' => true,
            'existing_article_id' => null,
            'article_reconcile_status' => ContentProjectExistingArticleReconcileResult::STATUS_MISSING,
            'resume_ok' => true,
            'resumable_from_step' => 'article',
            'can_rerun' => true,
        ]);

        self::assertSame(ContentProjectGenerationRecoveryDecision::ACTION_SELECT_EXISTING_ARTICLE, $decision->action);
        self::assertTrue($decision->showSelectExistingArticle());
        self::assertFalse($decision->showResume());
        self::assertFalse($decision->showRerun());
        self::assertSame('existing_article_unresolved', $decision->reason);
    }

    public function test_snapshot_decision_ambiguous_article_is_select_not_guess(): void
    {
        $decision = ContentProjectGenerationCapabilityResolver::decideFromSnapshot([
            'task_id' => 378,
            'requires_existing_article' => true,
            'existing_article_id' => null,
            'article_reconcile_status' => ContentProjectExistingArticleReconcileResult::STATUS_AMBIGUOUS,
            'can_rerun' => true,
        ]);

        self::assertSame(ContentProjectGenerationRecoveryDecision::ACTION_SELECT_EXISTING_ARTICLE, $decision->action);
        self::assertSame('existing_article_ambiguous', $decision->reason);
        self::assertTrue($decision->showSelectExistingArticle());
    }

    public function test_snapshot_decision_prefers_resume_over_rerun(): void
    {
        $decision = ContentProjectGenerationCapabilityResolver::decideFromSnapshot([
            'task_id' => 378,
            'requires_existing_article' => true,
            'existing_article_id' => 99,
            'resume_ok' => true,
            'resumable_from_step' => 'article',
            'resume_prerequisites_ok' => true,
            'can_rerun' => true,
            'repaired' => true,
        ]);

        self::assertSame(ContentProjectGenerationRecoveryDecision::ACTION_RESUME, $decision->action);
        self::assertTrue($decision->showResume());
        self::assertFalse($decision->showRerun());
        self::assertFalse($decision->showSelectExistingArticle());
        self::assertTrue($decision->repaired);
        self::assertSame(99, $decision->existingArticleId);
    }

    public function test_active_generation_hides_select_existing_article(): void
    {
        $decision = ContentProjectGenerationCapabilityResolver::decideFromSnapshot([
            'task_id' => 378,
            'is_genuinely_running' => true,
            'requires_existing_article' => true,
            'existing_article_id' => null,
            'article_reconcile_status' => ContentProjectExistingArticleReconcileResult::STATUS_MISSING,
        ]);
        self::assertSame(ContentProjectGenerationRecoveryDecision::ACTION_ACTIVE, $decision->action);
        self::assertFalse($decision->showSelectExistingArticle());

        $actions = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'generating',
            'generation_status' => 'writing',
            'generation_badge' => ['key' => 'running'],
            'is_genuinely_running' => true,
            'generation_recovery_action' => ContentProjectGenerationRecoveryDecision::ACTION_ACTIVE,
            'article_edit_url' => null,
        ]);
        self::assertFalse($actions['select_existing_article']);
        self::assertFalse($actions['resume_generation']);
        self::assertFalse($actions['create_or_rerun']);
    }

    public function test_manual_attach_capability_flips_select_to_resume_or_rerun(): void
    {
        $before = ContentProjectGenerationCapabilityResolver::decideFromSnapshot([
            'task_id' => 378,
            'requires_existing_article' => true,
            'existing_article_id' => null,
            'article_reconcile_status' => ContentProjectExistingArticleReconcileResult::STATUS_MISSING,
        ]);
        self::assertSame(ContentProjectGenerationRecoveryDecision::ACTION_SELECT_EXISTING_ARTICLE, $before->action);

        $afterResume = ContentProjectGenerationCapabilityResolver::decideFromSnapshot([
            'task_id' => 378,
            'requires_existing_article' => true,
            'existing_article_id' => 3145,
            'resume_ok' => true,
            'resumable_from_step' => 'article',
            'resume_prerequisites_ok' => true,
            'can_rerun' => true,
        ]);
        self::assertSame(ContentProjectGenerationRecoveryDecision::ACTION_RESUME, $afterResume->action);
        $resumeUi = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'failed',
            'generation_status' => 'failed',
            'generation_badge' => ['key' => 'failed'],
            'is_genuinely_running' => false,
            'generation_recovery_action' => $afterResume->action,
            'article_edit_url' => '/seo/articles/3145/edit',
        ]);
        self::assertTrue($resumeUi['resume_generation']);
        self::assertFalse($resumeUi['select_existing_article']);
        self::assertFalse($resumeUi['create_or_rerun']);

        $afterRerun = ContentProjectGenerationCapabilityResolver::decideFromSnapshot([
            'task_id' => 378,
            'requires_existing_article' => true,
            'existing_article_id' => 3145,
            'missing_article_preflight' => true,
            'resume_ok' => false,
            'can_rerun' => true,
        ]);
        self::assertSame(ContentProjectGenerationRecoveryDecision::ACTION_RERUN, $afterRerun->action);
        $rerunUi = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'failed',
            'generation_status' => 'failed',
            'generation_badge' => ['key' => 'failed'],
            'is_genuinely_running' => false,
            'generation_recovery_action' => $afterRerun->action,
            'article_edit_url' => '/seo/articles/3145/edit',
        ]);
        self::assertTrue($rerunUi['create_or_rerun']);
        self::assertSame('rerun', $rerunUi['create_or_rerun_label']);
        self::assertFalse($rerunUi['select_existing_article']);
        self::assertFalse($rerunUi['resume_generation']);
    }

    public function test_select_existing_command_and_handler_wiring(): void
    {
        $cmdSrc = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SelectExistingArticleForProjectItemCommand::class))->getFileName(),
        );
        $handlerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SelectExistingArticleForProjectItemHandler::class))->getFileName(),
        );
        $registrarSrc = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar::class))->getFileName(),
        );
        $pickerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExistingArticlePickerService::class))->getFileName(),
        );

        self::assertStringContainsString('content_project.select_existing_article', $cmdSrc);
        self::assertStringContainsString('ProjectTaskCallerBridge', $handlerSrc);
        self::assertStringContainsString('attachArticle', $handlerSrc);
        self::assertStringContainsString('generation_started', $handlerSrc);
        self::assertStringContainsString('article_wrong_site', $handlerSrc);
        self::assertStringContainsString('article_owned_by_active_task', $handlerSrc);
        self::assertStringContainsString('SelectExistingArticleForProjectItemCommand::class => SelectExistingArticleForProjectItemHandler::class', $registrarSrc);
        self::assertStringContainsString('wp_post_id', $pickerSrc);
        self::assertStringContainsString('wp_permalink', $pickerSrc);
        self::assertStringContainsString('function resolveDirect', $pickerSrc);
        self::assertStringNotContainsString('SeoArticle::create', $handlerSrc);
        self::assertStringNotContainsString('->create([', $handlerSrc);
    }

    public function test_ui_menu_exposes_select_existing_article_action(): void
    {
        $menu = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-actions-menu.blade.php'),
        );
        self::assertStringContainsString("select_existing_article", $menu);
        self::assertStringContainsString('open-select-existing-article', $menu);
        self::assertStringContainsString('item_action_select_existing_article', $menu);
    }

    public function test_snapshot_stale_path_exposes_rerun_not_resume(): void
    {
        $decision = ContentProjectGenerationCapabilityResolver::decideFromSnapshot([
            'task_id' => 12,
            'requires_existing_article' => false,
            'resume_ok' => false,
            'can_rerun' => true,
            'can_generate' => false,
        ]);

        self::assertSame(ContentProjectGenerationRecoveryDecision::ACTION_RERUN, $decision->action);
    }

    public function test_reconciler_pick_unambiguous_candidate(): void
    {
        $reconciler = new ContentProjectExistingArticleReconciler;

        $ok = $reconciler->pickUnambiguousCandidate([
            ['article_id' => 55, 'matched_by' => 'run_item.article_id'],
            ['article_id' => 55, 'matched_by' => 'article_meta.content_project_run'],
        ]);
        self::assertSame('ok', $ok['status']);
        self::assertSame(55, $ok['article_id']);
        self::assertSame('run_item.article_id', $ok['matched_by']);

        $ambiguous = $reconciler->pickUnambiguousCandidate([
            ['article_id' => 1, 'matched_by' => 'exact.slug'],
            ['article_id' => 2, 'matched_by' => 'exact.slug'],
        ]);
        self::assertSame('ambiguous', $ambiguous['status']);
        self::assertNull($ambiguous['article_id']);

        $missing = $reconciler->pickUnambiguousCandidate([]);
        self::assertSame('missing', $missing['status']);
    }

    public function test_exact_title_match_is_not_fuzzy(): void
    {
        $reconciler = new ContentProjectExistingArticleReconciler;
        $matches = $reconciler->matchExactTitleCandidates('Áo thun nam', [
            ['id' => 10, 'title' => 'Áo thun nam'],
            ['id' => 11, 'title' => 'Áo thun nam nữ'],
            ['id' => 12, 'title' => 'áo thun nam'],
        ]);

        self::assertSame([
            ['article_id' => 10, 'matched_by' => 'exact.title'],
        ], $matches);
    }

    public function test_task_378_broken_link_scenario_prefers_rerun_after_exact_title_repair(): void
    {
        // Production shape: rewrite task lost article_id; source_content still holds picker title;
        // latest failure is missing-article preflight — not a workflow step.
        $reconciler = new ContentProjectExistingArticleReconciler;
        $titleCandidates = $reconciler->matchExactTitleCandidates(
            'Bài viết rewrite target #378',
            [
                ['id' => 905, 'title' => 'Bài viết rewrite target #378'],
            ],
        );
        $pick = $reconciler->pickUnambiguousCandidate($titleCandidates);
        self::assertSame('ok', $pick['status']);
        self::assertSame(905, $pick['article_id']);
        self::assertSame('exact.title', $pick['matched_by']);

        $decision = ContentProjectGenerationCapabilityResolver::decideFromSnapshot([
            'task_id' => 378,
            'requires_existing_article' => true,
            'existing_article_id' => 905,
            'article_reconcile_status' => ContentProjectExistingArticleReconcileResult::STATUS_REPAIRED,
            'repaired' => true,
            'missing_article_preflight' => true,
            'resume_ok' => false,
            'can_generate' => false,
            'can_rerun' => true,
        ]);

        self::assertSame(ContentProjectGenerationRecoveryDecision::ACTION_RERUN, $decision->action);
        self::assertFalse($decision->showResume());
        self::assertTrue($decision->showRerun());
        self::assertTrue($decision->repaired);
        self::assertSame(905, $decision->existingArticleId);

        $actions = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'failed',
            'queue_status' => 'none',
            'generation_status' => 'failed',
            'generation_badge' => ['key' => 'failed'],
            'can_generate' => false,
            'can_regen' => true,
            'is_improve' => false,
            'is_generation_stale' => false,
            'is_genuinely_running' => false,
            'has_resumable_checkpoint' => false,
            'generation_recovery_action' => ContentProjectGenerationRecoveryDecision::ACTION_RERUN,
            'article_edit_url' => '/seo/articles/905/edit',
        ]);
        self::assertFalse($actions['resume_generation']);
        self::assertTrue($actions['create_or_rerun']);
        self::assertSame('rerun', $actions['create_or_rerun_label']);
    }

    public function test_missing_article_preflight_does_not_prefer_resume_even_if_step_mapped(): void
    {
        $decision = ContentProjectGenerationCapabilityResolver::decideFromSnapshot([
            'task_id' => 378,
            'requires_existing_article' => true,
            'existing_article_id' => 905,
            'repaired' => true,
            'missing_article_preflight' => true,
            'resume_ok' => true,
            'resumable_from_step' => 'article',
            'resume_prerequisites_ok' => true,
            'can_rerun' => true,
        ]);

        self::assertSame(ContentProjectGenerationRecoveryDecision::ACTION_RERUN, $decision->action);
        self::assertFalse($decision->showResume());
    }

    public function test_reconciler_does_not_use_fuzzy_title_matching(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectExistingArticleReconciler::class))->getFileName(),
        );
        self::assertStringNotContainsString('findArticleByTitle', $src);
        self::assertStringNotContainsString("where('title', 'like'", $src);
        self::assertStringContainsString("where('title', \$hint)", $src);
        self::assertStringContainsString('exact.title', $src);
        self::assertStringContainsString('exact.slug', $src);
        self::assertStringContainsString('exact.wp_permalink', $src);
        self::assertStringContainsString('exact.wp_post_id', $src);
        self::assertStringContainsString('content_project_run', $src);
        self::assertStringContainsString('run_item.article_id', $src);
        self::assertStringContainsString('run_item.output_snapshot.article_id', $src);
        self::assertStringContainsString('task_event.article_id', $src);
        self::assertStringContainsString('automation_origin.seo_project_task', $src);
        self::assertStringContainsString('Multiple candidate articles — refuse to guess', $src);
        self::assertStringContainsString('function diagnose', $src);
        self::assertStringContainsString('function needsAssociationRepair', $src);
    }

    public function test_task_input_resolver_uses_reconciler_not_title_like_for_rewrite(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\AiPrompt\Services\TaskTestInputResolver::class))->getFileName(),
        );
        self::assertStringContainsString('resolveExistingArticleForTask', $src);
        self::assertStringContainsString('existingArticleReconciler->reconcileTask', $src);
        self::assertStringContainsString('never fuzzy title LIKE', $src);
    }

    public function test_resume_handler_rechecks_capability(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ResumeProjectItemFromFailedStepHandler::class
            ))->getFileName(),
        );
        self::assertStringContainsString('capability->decide', $src);
        self::assertStringContainsString('articleReconciler->reconcileTask', $src);
        self::assertStringContainsString('ACTION_RESUME', $src);
    }

    public function test_read_model_wires_capability_and_article_repair(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel::class
            ))->getFileName(),
        );
        self::assertStringContainsString('existingArticleReconciler->reconcileProjectMissingLinks', $src);
        self::assertStringContainsString('generationCapability->decide', $src);
        self::assertStringContainsString('generation_recovery_action', $src);
        self::assertStringContainsString('resumable_from_step', $src);
    }

    public function test_decision_dto_xor_helpers(): void
    {
        $resume = new ContentProjectGenerationRecoveryDecision(
            taskId: 1,
            action: ContentProjectGenerationRecoveryDecision::ACTION_RESUME,
            reason: 'ok',
            resumableFromStep: 'outline',
            existingArticleId: 9,
        );
        self::assertTrue($resume->showResume());
        self::assertFalse($resume->showSmartCreateOrRerun());

        $rerun = new ContentProjectGenerationRecoveryDecision(
            taskId: 1,
            action: ContentProjectGenerationRecoveryDecision::ACTION_RERUN,
            reason: 'ok',
            existingArticleId: 9,
        );
        self::assertFalse($rerun->showResume());
        self::assertTrue($rerun->showSmartCreateOrRerun());
    }
}
