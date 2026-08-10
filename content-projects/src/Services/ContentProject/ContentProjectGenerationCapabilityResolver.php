<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectRerunEligibilityGuard;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Schema;

/**
 * Single capability decision for Resume / Run again / Generate / Select Existing / Active / None.
 * Shared by row UI, detail UI, and CommandBus handlers.
 */
final class ContentProjectGenerationCapabilityResolver
{
    public function __construct(
        private readonly ContentProjectExistingArticleReconciler $articleReconciler,
        private readonly ContentProjectGenerationRecoveryService $staleRecovery,
        private readonly ContentProjectExecutionStalenessPolicy $staleness,
        private readonly ContentProjectFailedStepResumeResolver $resumeResolver,
        private readonly ContentProjectItemGenerationClassifier $classifier,
        private readonly ContentProjectRerunEligibilityGuard $eligibility,
        private readonly ArticleOutlineResolver $outlineResolver,
        private readonly ArticleGenerationInputResolver $generationInput,
    ) {}

    /**
     * @param  array{
     *     recover_stale?: bool,
     *     persist_article_repair?: bool,
     * }  $options
     */
    public function decide(SeoProject $project, SeoProjectTask $task, array $options = []): ContentProjectGenerationRecoveryDecision
    {
        $taskId = (int) $task->getKey();
        $recoverStale = ($options['recover_stale'] ?? false) === true;
        $persistRepair = ($options['persist_article_repair'] ?? true) === true;
        $evidence = [];
        $staleRecovered = false;

        if ($recoverStale) {
            $recovery = $this->staleRecovery->recoverTaskIfStale($task);
            $staleRecovered = ($recovery['recovered'] ?? false) === true;
            if ($staleRecovered) {
                $evidence[] = 'stale_recovered';
            }
            $task->refresh();
        }

        $task->loadMissing('article');

        $articleResult = $this->articleReconciler->reconcileTask(
            $task,
            (int) ($project->site_id ?? 0) > 0 ? (int) $project->site_id : null,
            persist: $persistRepair,
        );
        if ($articleResult->persisted) {
            $task->refresh();
            $task->loadMissing('article');
            $evidence[] = 'article_repaired:'.($articleResult->matchedBy ?: 'unknown');
        } elseif ($articleResult->isUsable()) {
            $evidence[] = 'article_resolved:'.($articleResult->matchedBy ?: 'task.article_id');
        } elseif ($articleResult->status !== ContentProjectExistingArticleReconcileResult::STATUS_NOT_REQUIRED) {
            $evidence[] = 'article_'.$articleResult->status;
        }

        $existingArticleId = $articleResult->isUsable()
            ? (int) $articleResult->articleId
            : ((int) ($task->article_id ?? 0) > 0 ? (int) $task->article_id : null);

        $evaluation = $this->staleness->evaluateTask($task);
        if (
            ($evaluation['has_fresh_active_execution'] ?? false) === true
            || ($evaluation['has_valid_owned_lock'] ?? false) === true
        ) {
            return new ContentProjectGenerationRecoveryDecision(
                taskId: $taskId,
                action: ContentProjectGenerationRecoveryDecision::ACTION_ACTIVE,
                reason: ContentProjectItemGenerationLaunchPlanner::ACTIVE_MESSAGE,
                existingArticleId: $existingArticleId,
                repairable: $articleResult->status === ContentProjectExistingArticleReconcileResult::STATUS_MISSING,
                repaired: $articleResult->persisted,
                staleRecovered: $staleRecovered,
                evidence: $evidence,
            );
        }

        if ($task->isGenerationBlocked()) {
            return new ContentProjectGenerationRecoveryDecision(
                taskId: $taskId,
                action: ContentProjectGenerationRecoveryDecision::ACTION_NONE,
                reason: 'Item đã được đánh dấu bỏ qua tạo bài.',
                existingArticleId: $existingArticleId,
                repaired: $articleResult->persisted,
                staleRecovered: $staleRecovered,
                evidence: $evidence,
            );
        }

        $requiresArticle = in_array(
            SeoProjectTask::normalizeType($task->type),
            SeoProjectTask::typesRequiringExistingArticle(),
            true,
        );
        if ($requiresArticle && ! $articleResult->isUsable()) {
            $reason = $this->selectExistingArticleReason($articleResult);
            RuntimeLogger::info('content_project.generation_capability_select_existing_article', [
                'task_id' => $taskId,
                'project_id' => (int) $project->getKey(),
                'reason' => $reason,
                'article_status' => $articleResult->status,
                'matched_by' => $articleResult->matchedBy,
                'diagnose' => $this->articleReconciler->diagnose($task, (int) ($project->site_id ?? 0) ?: null, persist: false),
            ]);

            return new ContentProjectGenerationRecoveryDecision(
                taskId: $taskId,
                action: ContentProjectGenerationRecoveryDecision::ACTION_SELECT_EXISTING_ARTICLE,
                reason: $reason,
                existingArticleId: null,
                repairable: true,
                repaired: false,
                staleRecovered: $staleRecovered,
                evidence: $evidence,
            );
        }

        $resumePlan = $this->resumeResolver->resolve($task);
        $missingArticlePreflight = $this->isMissingArticlePreflightFailure($task);
        if (
            ! $missingArticlePreflight
            && ($resumePlan['ok'] ?? false) === true
            && $resumePlan['from_step'] instanceof ContentProjectRerunFromStep
        ) {
            $resumeBlock = $this->resumePrerequisiteFailure(
                $task,
                $resumePlan['from_step'],
                $existingArticleId,
            );
            if ($resumeBlock === null) {
                $evidence[] = 'resume_ok:'.$resumePlan['from_step']->value;

                return new ContentProjectGenerationRecoveryDecision(
                    taskId: $taskId,
                    action: ContentProjectGenerationRecoveryDecision::ACTION_RESUME,
                    reason: (string) ($resumePlan['message'] ?? 'Resume from failed step.'),
                    resumableFromStep: $resumePlan['from_step']->value,
                    existingArticleId: $existingArticleId,
                    repairable: true,
                    repaired: $articleResult->persisted,
                    staleRecovered: $staleRecovered,
                    evidence: $evidence,
                );
            }
            $evidence[] = 'resume_blocked:'.$resumeBlock;
        } elseif ($missingArticlePreflight) {
            $evidence[] = 'resume_skip_missing_article_preflight';
        } else {
            $evidence[] = 'resume_unavailable:'.(string) ($resumePlan['message'] ?? 'n/a');
        }

        $decision = $this->classifier->decisionForTask($task);
        if ($decision->shouldRun()) {
            return new ContentProjectGenerationRecoveryDecision(
                taskId: $taskId,
                action: ContentProjectGenerationRecoveryDecision::ACTION_GENERATE,
                reason: $decision->reason,
                existingArticleId: $existingArticleId,
                repaired: $articleResult->persisted,
                staleRecovered: $staleRecovered,
                evidence: array_merge($evidence, $decision->evidence),
            );
        }

        $gate = $this->eligibility->validateFull($project, [$taskId]);
        if ($gate['ok'] && in_array($taskId, $gate['eligible_ids'], true)) {
            return new ContentProjectGenerationRecoveryDecision(
                taskId: $taskId,
                action: ContentProjectGenerationRecoveryDecision::ACTION_RERUN,
                reason: $missingArticlePreflight
                    ? 'Existing Article repaired — clean rerun (preflight failure not resumable).'
                    : 'Clean rerun eligible.',
                existingArticleId: $existingArticleId,
                repaired: $articleResult->persisted,
                staleRecovered: $staleRecovered,
                evidence: $evidence,
            );
        }

        $rejectReason = (string) ($gate['rejected'][0]['reason'] ?? $decision->reason ?? 'Not executable.');
        RuntimeLogger::info('content_project.generation_capability_none', [
            'task_id' => $taskId,
            'project_id' => (int) $project->getKey(),
            'reason' => $rejectReason,
            'existing_article_id' => $existingArticleId,
            'evidence' => $evidence,
            'eligibility' => $gate,
        ]);

        return new ContentProjectGenerationRecoveryDecision(
            taskId: $taskId,
            action: ContentProjectGenerationRecoveryDecision::ACTION_NONE,
            reason: $rejectReason,
            existingArticleId: $existingArticleId,
            repaired: $articleResult->persisted,
            staleRecovered: $staleRecovered,
            evidence: $evidence,
        );
    }

    /**
     * Latest failed attempt is the missing Existing Article preflight — not a workflow step.
     * After repair, prefer clean rerun over Resume.
     */
    private function isMissingArticlePreflightFailure(SeoProjectTask $task): bool
    {
        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('seo_project_run_items')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $latest = \Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem::query()
            ->where('task_id', (int) $task->getKey())
            ->orderByDesc('id')
            ->first(['error_message', 'message', 'status']);
        if ($latest === null) {
            return false;
        }

        $error = mb_strtolower(trim((string) ($latest->error_message ?? $latest->message ?? '')));
        if ($error === '') {
            return false;
        }

        return str_contains($error, 'không tìm thấy bài viết')
            && (
                str_contains($error, 'existing article')
                || str_contains($error, 'target / existing')
                || str_contains($error, 'viết lại')
                || str_contains($error, 'cải thiện')
            );
    }

    /**
     * Snapshot-friendly decision for unit tests / presenter fallbacks.
     *
     * @param  array{
     *     task_id?: int,
     *     is_genuinely_running?: bool,
     *     generation_blocked?: bool,
     *     requires_existing_article?: bool,
     *     existing_article_id?: int|null,
     *     article_reconcile_status?: string,
     *     resume_ok?: bool,
     *     resumable_from_step?: string|null,
     *     resume_prerequisites_ok?: bool,
     *     missing_article_preflight?: bool,
     *     can_generate?: bool,
     *     can_rerun?: bool,
     *     repaired?: bool,
     * }  $snapshot
     */
    public static function decideFromSnapshot(array $snapshot): ContentProjectGenerationRecoveryDecision
    {
        $taskId = (int) ($snapshot['task_id'] ?? 0);
        $articleId = isset($snapshot['existing_article_id']) ? (int) $snapshot['existing_article_id'] : null;
        if ($articleId !== null && $articleId <= 0) {
            $articleId = null;
        }
        $repaired = ! empty($snapshot['repaired']);

        if (! empty($snapshot['is_genuinely_running'])) {
            return new ContentProjectGenerationRecoveryDecision(
                taskId: $taskId,
                action: ContentProjectGenerationRecoveryDecision::ACTION_ACTIVE,
                reason: 'active',
                existingArticleId: $articleId,
                repaired: $repaired,
            );
        }

        if (! empty($snapshot['generation_blocked'])) {
            return new ContentProjectGenerationRecoveryDecision(
                taskId: $taskId,
                action: ContentProjectGenerationRecoveryDecision::ACTION_NONE,
                reason: 'generation_blocked',
                existingArticleId: $articleId,
                repaired: $repaired,
            );
        }

        $requiresArticle = ! empty($snapshot['requires_existing_article']);
        $articleStatus = (string) ($snapshot['article_reconcile_status'] ?? (
            $articleId !== null ? ContentProjectExistingArticleReconcileResult::STATUS_RESOLVED : ContentProjectExistingArticleReconcileResult::STATUS_MISSING
        ));
        if ($requiresArticle && $articleId === null) {
            $selectReason = match ($articleStatus) {
                ContentProjectExistingArticleReconcileResult::STATUS_AMBIGUOUS => 'existing_article_ambiguous',
                ContentProjectExistingArticleReconcileResult::STATUS_CONFLICT => 'article_owned_by_active_task',
                default => 'existing_article_unresolved',
            };

            return new ContentProjectGenerationRecoveryDecision(
                taskId: $taskId,
                action: ContentProjectGenerationRecoveryDecision::ACTION_SELECT_EXISTING_ARTICLE,
                reason: $selectReason,
                repairable: true,
                repaired: false,
            );
        }

        $skipResume = ! empty($snapshot['missing_article_preflight']);
        if (
            ! $skipResume
            && ! empty($snapshot['resume_ok'])
            && ($snapshot['resume_prerequisites_ok'] ?? true) === true
            && trim((string) ($snapshot['resumable_from_step'] ?? '')) !== ''
        ) {
            return new ContentProjectGenerationRecoveryDecision(
                taskId: $taskId,
                action: ContentProjectGenerationRecoveryDecision::ACTION_RESUME,
                reason: 'resume',
                resumableFromStep: (string) $snapshot['resumable_from_step'],
                existingArticleId: $articleId,
                repairable: true,
                repaired: $repaired,
            );
        }

        if (! empty($snapshot['can_generate'])) {
            return new ContentProjectGenerationRecoveryDecision(
                taskId: $taskId,
                action: ContentProjectGenerationRecoveryDecision::ACTION_GENERATE,
                reason: 'generate',
                existingArticleId: $articleId,
                repaired: $repaired,
            );
        }

        if (! empty($snapshot['can_rerun'])) {
            return new ContentProjectGenerationRecoveryDecision(
                taskId: $taskId,
                action: ContentProjectGenerationRecoveryDecision::ACTION_RERUN,
                reason: $skipResume
                    ? 'Existing Article repaired — clean rerun (preflight failure not resumable).'
                    : 'rerun',
                existingArticleId: $articleId,
                repaired: $repaired,
            );
        }

        return new ContentProjectGenerationRecoveryDecision(
            taskId: $taskId,
            action: ContentProjectGenerationRecoveryDecision::ACTION_NONE,
            reason: 'none',
            existingArticleId: $articleId,
            repaired: $repaired,
        );
    }

    private function selectExistingArticleReason(
        ContentProjectExistingArticleReconcileResult $articleResult,
    ): string {
        return match ($articleResult->status) {
            ContentProjectExistingArticleReconcileResult::STATUS_AMBIGUOUS => 'existing_article_ambiguous',
            ContentProjectExistingArticleReconcileResult::STATUS_CONFLICT => 'article_owned_by_active_task',
            default => 'existing_article_unresolved',
        };
    }

    private function resumePrerequisiteFailure(
        SeoProjectTask $task,
        ContentProjectRerunFromStep $fromStep,
        ?int $existingArticleId,
    ): ?string {
        $requiresArticle = in_array(
            SeoProjectTask::normalizeType($task->type),
            SeoProjectTask::typesRequiringExistingArticle(),
            true,
        );
        if ($requiresArticle && ($existingArticleId === null || $existingArticleId <= 0)) {
            return 'existing_article_missing';
        }

        if ($fromStep === ContentProjectRerunFromStep::Article) {
            $articleId = $existingArticleId ?? (int) ($task->article_id ?? 0);
            if ($articleId <= 0) {
                return 'article_required_for_content_resume';
            }
            $article = SeoArticle::query()->find($articleId);
            if (! $article instanceof SeoArticle) {
                return 'article_missing';
            }
            $outline = trim((string) $this->outlineResolver->resolveMarkdown($article));
            if ($outline === '') {
                try {
                    $resolved = $this->generationInput->resolveForArticle($article);
                    $outline = trim((string) ($resolved->rawArtifact ?? ''));
                } catch (\Throwable) {
                    $outline = '';
                }
            }
            if ($outline === '') {
                return 'outline_missing_for_article_resume';
            }

            return null;
        }

        // Outline resume: rewrite may use existing article; create needs keyword/title.
        if ($requiresArticle) {
            return null;
        }

        if (! ContentProjectItemIdentity::isValid(
            $task->keyword !== null ? (string) $task->keyword : null,
            $task->title !== null ? (string) $task->title : null,
        )) {
            return ContentProjectItemIdentity::failureMessage();
        }

        return null;
    }
}
