<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFreshKeywordRestart;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGenerationKeyword;

/**
 * Lazy per-item decision at claim/execute time for a Content Project bulk run.
 */
final class ContentProjectBulkItemDecisionService
{
    public function __construct(
        private readonly ContentProjectGenerationCapabilityResolver $capability,
        private readonly ContentProjectFailedStepResumeResolver $resumeResolver,
        private readonly ?ArticleEditorSessionService $editorSessions = null,
    ) {}

    /**
     * @param  array{allow_improve_generation?: bool, recover_stale?: bool}  $options
     */
    public function decide(SeoProject $project, SeoProjectTask $task, array $options = []): ContentProjectBulkItemDecision
    {
        $taskId = (int) $task->getKey();
        $task->refresh();
        $task->loadMissing('article');

        $type = SeoProjectTask::normalizeType((string) ($task->type ?? ''));
        $allowImprove = ($options['allow_improve_generation'] ?? false) === true;

        if (! $allowImprove && $type === SeoProjectTask::TYPE_IMPROVE) {
            return $this->skip($taskId, 'improve_manual_only');
        }

        if ($task->archived_at !== null || $task->isGenerationBlocked()) {
            return $this->skip($taskId, 'ineligible_blocked');
        }

        $status = strtolower(trim((string) ($task->status ?? '')));
        if (in_array($status, [
            SeoProjectTask::STATUS_COMPLETED,
            'completed',
            'published',
            SeoProjectTask::STATUS_CANCELLED,
            'cancelled',
            'archived',
        ], true)) {
            // Keyword-dirty completed items may still need restart — checked via capability below.
            // Pure completed without dirty → skip after capability says none/rerun.
        }

        $decision = $this->capability->decide($project, $task, [
            'recover_stale' => ($options['recover_stale'] ?? true) === true,
            'persist_article_repair' => true,
        ]);

        if ($decision->action === ContentProjectGenerationRecoveryDecision::ACTION_ACTIVE) {
            return $this->skip($taskId, 'state_conflict_active', ['capability' => $decision->toArray()]);
        }

        if ($decision->action === ContentProjectGenerationRecoveryDecision::ACTION_SELECT_EXISTING_ARTICLE) {
            return $this->skip($taskId, 'select_existing_article', ['capability' => $decision->toArray()]);
        }

        if ($decision->action === ContentProjectGenerationRecoveryDecision::ACTION_RESUME) {
            return $this->decideResume($task, $decision);
        }

        if ($decision->action === ContentProjectGenerationRecoveryDecision::ACTION_GENERATE) {
            if ($decision->reason === ContentProjectGenerationKeyword::REASON_DIRTY) {
                return $this->decideRestart($task, $decision);
            }

            if ($this->shouldSkipRewriteForActiveEditor($task)) {
                return $this->skip($taskId, 'active_editor_session', [
                    'capability' => $decision->toArray(),
                    'article_id' => (int) ($task->article_id ?? 0),
                ]);
            }

            return new ContentProjectBulkItemDecision(
                taskId: $taskId,
                operation: ContentProjectBulkItemDecision::OP_GENERATE_NEW,
                reason: $decision->reason !== '' ? $decision->reason : 'generate_new',
                executionSettings: $this->generateSettings(),
                meta: ['capability' => $decision->toArray()],
            );
        }

        // ACTION_RERUN / ACTION_NONE — bulk generate does not auto-rerun completed articles.
        return $this->skip(
            $taskId,
            $decision->action === ContentProjectGenerationRecoveryDecision::ACTION_RERUN
                ? 'already_complete_or_manual_rerun_only'
                : ($decision->reason !== '' ? $decision->reason : 'ineligible'),
            ['capability' => $decision->toArray()],
        );
    }

    private function decideResume(
        SeoProjectTask $task,
        ContentProjectGenerationRecoveryDecision $decision,
    ): ContentProjectBulkItemDecision {
        $taskId = (int) $task->getKey();
        $plan = $this->resumeResolver->resolve($task);
        if (! ($plan['ok'] ?? false) || $plan['from_step'] === null) {
            return $this->skip($taskId, (string) ($plan['message'] ?? 'resume_unresolvable'), [
                'capability' => $decision->toArray(),
            ]);
        }

        if ($this->shouldSkipRewriteForActiveEditor($task)) {
            return $this->skip($taskId, 'active_editor_session', [
                'capability' => $decision->toArray(),
                'article_id' => (int) ($task->article_id ?? 0),
            ]);
        }

        $fromStep = $plan['from_step'];
        $settings = [
            'rerun' => true,
            'rerun_scope' => 'step',
            'rerun_from_step' => $fromStep->value,
            'rerun_include_downstream' => (bool) ($plan['include_downstream'] ?? true),
            'resume_partial_split' => true,
        ];
        if (! empty($plan['run_item_id'])) {
            $settings['resume_prior_run_item_id'] = (int) $plan['run_item_id'];
        }
        if (is_array($plan['split_progress'] ?? null)) {
            $settings['resume_split_progress'] = $plan['split_progress'];
        }

        return new ContentProjectBulkItemDecision(
            taskId: $taskId,
            operation: ContentProjectBulkItemDecision::OP_RESUME_FROM_FAILED_STEP,
            reason: (string) ($plan['message'] ?? $decision->reason),
            executionSettings: $settings,
            meta: [
                'capability' => $decision->toArray(),
                'resumed_from_step' => $plan['resumed_from_step'] ?? $fromStep->value,
                'reused_steps' => $plan['reused_steps'] ?? [],
                'prior_run_item_id' => $plan['run_item_id'] ?? null,
            ],
        );
    }

    private function decideRestart(
        SeoProjectTask $task,
        ContentProjectGenerationRecoveryDecision $decision,
    ): ContentProjectBulkItemDecision {
        $taskId = (int) $task->getKey();
        $keyword = ContentProjectGenerationKeyword::effective($task);
        if ($keyword === '') {
            return $this->skip($taskId, 'restart_keyword_empty', ['capability' => $decision->toArray()]);
        }

        if ($this->shouldSkipRewriteForActiveEditor($task)) {
            return $this->skip($taskId, 'active_editor_session', [
                'capability' => $decision->toArray(),
                'article_id' => (int) ($task->article_id ?? 0),
            ]);
        }

        return new ContentProjectBulkItemDecision(
            taskId: $taskId,
            operation: ContentProjectBulkItemDecision::OP_RESTART_WITH_KEYWORD,
            reason: ContentProjectGenerationKeyword::REASON_DIRTY,
            executionSettings: [
                'rerun' => true,
                'rerun_scope' => 'full',
                ContentProjectFreshKeywordRestart::SETTING_MODE => ContentProjectFreshKeywordRestart::MODE,
                ContentProjectFreshKeywordRestart::SETTING_KEYWORD => $keyword,
            ],
            meta: [
                'capability' => $decision->toArray(),
                'keyword' => $keyword,
                'needs_workspace_reset' => true,
            ],
        );
    }

    /**
     * Rewrite + any active editor session (any user, including bulk actor) → skip item.
     * Stale sessions (TTL expired) do not block — SessionService sweeps on find.
     */
    private function shouldSkipRewriteForActiveEditor(SeoProjectTask $task): bool
    {
        $type = SeoProjectTask::normalizeType((string) ($task->type ?? ''));
        if ($type !== SeoProjectTask::TYPE_REWRITE) {
            return false;
        }

        $articleId = (int) ($task->article_id ?? 0);
        if ($articleId <= 0) {
            return false;
        }

        $sessions = $this->editorSessions ?? (class_exists(ArticleEditorSessionService::class)
            ? app(ArticleEditorSessionService::class)
            : null);
        if (! $sessions instanceof ArticleEditorSessionService) {
            return false;
        }

        $article = $task->relationLoaded('article') && $task->article instanceof SeoArticle
            ? $task->article
            : SeoArticle::query()->find($articleId);

        if (! $article instanceof SeoArticle) {
            return false;
        }

        return $sessions->findActiveSession($article) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function generateSettings(): array
    {
        return [
            'rerun' => false,
            'rerun_scope' => null,
            'rerun_from_step' => null,
            'rerun_include_downstream' => false,
            'resume_partial_split' => false,
            'resume_prior_run_item_id' => null,
            'resume_split_progress' => null,
            ContentProjectFreshKeywordRestart::SETTING_MODE => null,
            ContentProjectFreshKeywordRestart::SETTING_KEYWORD => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function skip(int $taskId, string $reason, array $meta = []): ContentProjectBulkItemDecision
    {
        return new ContentProjectBulkItemDecision(
            taskId: $taskId,
            operation: ContentProjectBulkItemDecision::OP_SKIP,
            reason: $reason,
            meta: $meta,
        );
    }
}
