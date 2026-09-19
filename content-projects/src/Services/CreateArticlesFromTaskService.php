<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;


use Omnichannel\Addons\Content\Services\ArticleImproveExecutionService;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Seo\Services\SeoMainDomainService;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationMigrationWriteException;
use Omnichannel\Addons\Agent\Automation\Migration\ProjectArticleCreateCallerBridge;
use Omnichannel\Addons\Agent\Automation\Runtime\ActionRunner;
use Omnichannel\Addons\Agent\Automation\Support\ArticleCreateOriginResolver;
use Omnichannel\Addons\Content\Enums\ArticleWritingExecutionMode;
use Omnichannel\Addons\AiPrompt\Enums\ArticleWritingPromptOwnerType;
use Omnichannel\Addons\AiPrompt\Services\TaskTestInputResolver;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionScope;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\LocalArticleAssociationGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectCreateGenerationGuard;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionRoleResolver;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionSnapshotBuilder;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionContext;
use Omnichannel\Addons\Content\Support\ArticleWritingInput;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
use Omnichannel\Addons\SearchFoundation\Services\DomainLinkListKeywordSyncService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskOriginVariables;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CreateArticlesFromTaskService
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
        private readonly TaskTestInputResolver $inputResolver,
        private readonly TaskWorkflowTestRunner $workflowRunner,
        private readonly SeoMainDomainService $mainDomain,
        private readonly DomainLinkListKeywordSyncService $linkListSync,
        private readonly ProjectArticleCreateCallerBridge $articleCreateBridge,
        private readonly ActionRunner $actionRunner,
        private readonly ArticleCreateOriginResolver $originResolver,
        private readonly ArticleWritingExecutionService $articleWriting,
        private readonly ArticleImproveExecutionService $articleImprove,
        private readonly WorkflowExecutionRoleResolver $roleResolver,
        private readonly ArticleGenerationInputResolver $outlineResolver,
        private readonly ArticleOutlineResolver $articleOutlinePersist,
        private readonly WorkflowExecutionSnapshotBuilder $workflowSnapshotBuilder,
    ) {}

    /**
     * @return array{created: int, failed: int, messages: list<string>}
     */
    public function runFromKeywords(string $keywordsRaw): array
    {
        $siteId = $this->mainDomain->resolveMainSiteId();
        if ($siteId === null) {
            throw new \InvalidArgumentException(
                'Chưa có miền chính. Vào SEO → Danh sách tên miền → «Đặt làm chính» cho một domain.',
            );
        }

        return $this->runFromKeywordsForSite($keywordsRaw, $siteId);
    }

    /**
     * @return array{created: int, failed: int, messages: list<string>}
     */
    public function runFromKeywordsForSite(string $keywordsRaw, int $siteId): array
    {
        $taskId = $this->settings->getPublishArticleTaskId();
        if ($taskId === null) {
            throw new \InvalidArgumentException(
                'Chưa cấu hình quy trình Đăng bài viết. Vào SEO → Tùy chỉnh để chọn task.',
            );
        }

        $task = SeoTask::query()->find($taskId);
        if ($task === null) {
            throw new \InvalidArgumentException('Quy trình tạo bài viết (#'.$taskId.') không tồn tại.');
        }

        if (! $task->is_active) {
            throw new \InvalidArgumentException('Quy trình «'.$task->name.'» đang tắt. Hãy kích hoạt hoặc chọn task khác.');
        }

        $this->assertSiteAccessible($siteId);
        $this->syncDomainLinkListKeywords($siteId);

        $keywords = $this->parseKeywords($keywordsRaw);
        if ($keywords === []) {
            throw new \InvalidArgumentException('Nhập ít nhất một từ khóa.');
        }

        $scope = function (Builder $builder): void {
            if (! SeoAccessControl::shouldScopeToAccountOwner()) {
                return;
            }

            $builder->whereIn(
                'site_id',
                SeoAccessControl::accessibleSitesQuery()->select('id'),
            );
        };

        $created = 0;
        $failed = 0;
        $messages = [];
        $articleIds = [];

        foreach ($keywords as $keyword) {
            try {
                $context = $this->inputResolver->resolve(null, $keyword, $keyword, $scope)
                    ->withProjectTaskType(SeoProjectTask::TYPE_CREATE);
                $result = $this->runArticleWritingForContext(
                    $context,
                    $task,
                    $siteId,
                    $keyword,
                    ArticleWritingExecutionMode::PublishGraph,
                    ArticleWritingSourceType::Outline,
                );

                if (! ($result['success'] ?? false)) {
                    $failed++;
                    $messages[] = '«'.$keyword.'»: '.(string) ($result['message'] ?? 'quy trình lỗi.');

                    continue;
                }

                $created++;
                $articleIds[] = (int) ($result['article_id'] ?? 0);
                $messages[] = '«'.$keyword.'»: đã tạo bài nháp và chạy quy trình.';
            } catch (\Throwable $exception) {
                $failed++;
                $messages[] = '«'.$keyword.'»: '.$exception->getMessage();
            }
        }

        return [
            'created' => $created,
            'failed' => $failed,
            'messages' => $messages,
            'article_ids' => $articleIds,
        ];
    }

    /**
     * Chạy quy trình Đăng bài viết (SEO → Tùy chỉnh) cho một từ khóa trên domain cụ thể.
     *
     * @return array{created: int, failed: int, messages: list<string>, article_ids: list<int>}
     */
    public function runFromSingleKeyword(string $keyword, int $siteId): array
    {
        return $this->runFromKeywordsForSite(trim($keyword), $siteId);
    }

    /**
     * @return array{success: bool, article_id: ?int, message: string, steps: list<array<string, mixed>>}
     */
    /**
     * Step-scoped rerun for CommandBus / RunEngine worker.
     * Outline-only = single outline node; outline+downstream = outline then article;
     * article = content node with existing outline (no outline regen).
     *
     * @return array{success: bool, article_id: ?int, message: string, steps: list<array<string, mixed>>}
     */
    public function runRerunFromStepForContext(
        TaskTestContext $context,
        int $siteId,
        \Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep $fromStep,
        bool $includeDownstream = false,
    ): array {
        $projectType = SeoProjectTask::normalizeType((string) ($context->projectTaskType ?? ''));
        if ($projectType === SeoProjectTask::TYPE_IMPROVE) {
            return $this->runPublishWorkflowForContext($context, $siteId);
        }

        if ($fromStep === \Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep::Article) {
            $taskId = $this->settings->getPublishArticleTaskId();
            if ($taskId === null) {
                throw new \InvalidArgumentException(
                    'Chưa cấu hình quy trình Đăng bài viết. Vào SEO → Cài đặt → Quy trình để chọn task.',
                );
            }
            $task = SeoTask::query()->find($taskId);
            if (! $task instanceof SeoTask || ! $task->is_active) {
                throw new \InvalidArgumentException('Quy trình Đăng bài viết không khả dụng.');
            }
            $resolvedSiteId = (int) ($context->siteId ?? $siteId);
            $this->assertSiteAccessible($resolvedSiteId);
            $keyword = ContentProjectItemIdentity::topic(
                isset($context->variables['post_title']) ? (string) $context->variables['post_title'] : null,
                isset($context->variables['focus_keyword']) ? (string) $context->variables['focus_keyword'] : null,
            );
            if ($keyword === '') {
                $keyword = 'rewrite';
            }

            // Explicit step rerun must call AI — never short-circuit on existing body.
            $context = $this->withForcedAiRegenerate($context, $fromStep->value);

            return $this->runArticleWritingForContext(
                $context,
                $task,
                $resolvedSiteId,
                $keyword,
                ArticleWritingExecutionMode::ContentNode,
                ArticleWritingSourceType::Outline,
            );
        }

        if ($includeDownstream) {
            return $this->runOutlineThenArticleForContext(
                $this->withForcedAiRegenerate($context, $fromStep->value),
                $siteId,
            );
        }

        // Outline-only: vẫn chạy Extract keywords + Save vocabulary research (canonical path).
        // Không gọi single-node runner — tránh bypass action lưu vocabulary.
        $taskId = $this->settings->getPublishArticleTaskId();
        if ($taskId === null) {
            throw new \InvalidArgumentException(
                'Chưa cấu hình quy trình Đăng bài viết. Vào SEO → Cài đặt → Quy trình để chọn task.',
            );
        }
        $task = SeoTask::query()->find($taskId);
        if (! $task instanceof SeoTask || ! $task->is_active) {
            throw new \InvalidArgumentException('Quy trình Đăng bài viết không khả dụng.');
        }

        $resolvedSiteId = (int) ($context->siteId ?? $siteId);
        $this->assertSiteAccessible($resolvedSiteId);
        $outlineNodeId = $this->roleResolver->requireNodeId(
            $task,
            WorkflowExecutionRole::ArticleOutlineGenerate,
        );
        $context = $this->withForcedAiRegenerate($context, $fromStep->value);
        try {
            ContentProjectCreateGenerationGuard::assertBeforeAi($context, $resolvedSiteId);
        } catch (\InvalidArgumentException $exception) {
            return [
                'success' => false,
                'article_id' => $context->article?->id,
                'steps' => [],
                'message' => $exception->getMessage(),
            ];
        }

        $steps = $this->workflowRunner->runFromNodeId(
            $task,
            $context,
            $outlineNodeId,
            seedOutlineFromArticle: false,
            executionScope: WorkflowExecutionScope::OutlineVocabulary,
        );

        return $this->finalizeWorkflowGraphRun(
            $context,
            $task,
            $resolvedSiteId,
            ContentProjectItemIdentity::topic(
                isset($context->variables['post_title']) ? (string) $context->variables['post_title'] : null,
                isset($context->variables['focus_keyword']) ? (string) $context->variables['focus_keyword'] : null,
            ) ?: 'rewrite',
            $steps,
            requireContent: false,
        );
    }

    public function runPublishWorkflowForContext(TaskTestContext $context, int $siteId): array
    {
        $projectType = SeoProjectTask::normalizeType((string) ($context->projectTaskType ?? ''));

        // Improve tách hoàn toàn — không Publish / article.content.generate.
        if ($projectType === SeoProjectTask::TYPE_IMPROVE) {
            return $this->articleImprove->executeFromTaskContext($context);
        }

        $isContentRewrite = $context->rewriteMode === SeoProjectTask::REWRITE_MODE_CONTENT
            || $projectType === SeoProjectTask::TYPE_REWRITE;
        // Phase 0.6: CREATE/REWRITE dùng ArticleWritingExecutionService. Không đọc rewrite_article_task_id.
        $taskId = $this->settings->getPublishArticleTaskId();

        if ($taskId === null) {
            throw new \InvalidArgumentException(
                'Chưa cấu hình quy trình Đăng bài viết. Vào SEO → Cài đặt → Quy trình để chọn task.',
            );
        }

        $task = SeoTask::query()->find($taskId);
        if ($task === null) {
            throw new \InvalidArgumentException('Quy trình tạo bài viết (#'.$taskId.') không tồn tại.');
        }

        if (! $task->is_active) {
            throw new \InvalidArgumentException('Quy trình «'.$task->name.'» đang tắt.');
        }

        $resolvedSiteId = (int) ($context->siteId ?? $siteId);
        $this->assertSiteAccessible($resolvedSiteId);
        $this->syncDomainLinkListKeywords($resolvedSiteId);

        $focusKeyword = ContentProjectItemIdentity::normalize(
            isset($context->variables['focus_keyword']) ? (string) $context->variables['focus_keyword'] : null,
        );
        $postTitle = ContentProjectItemIdentity::normalize(
            isset($context->variables['post_title']) ? (string) $context->variables['post_title'] : null,
        );

        if (! ContentProjectItemIdentity::isValid($focusKeyword, $postTitle)
            && ! ($isContentRewrite && $context->article !== null)
        ) {
            return [
                'success' => false,
                'article_id' => null,
                'steps' => [],
                'message' => $isContentRewrite
                    ? 'Thiếu từ khóa / tiêu đề (hoặc bài viết nguồn).'
                    : ContentProjectItemIdentity::failureMessage(),
            ];
        }

        // Working topic label for draft/logging — not persisted as fake user keyword/title.
        $keyword = ContentProjectItemIdentity::topic($postTitle, $focusKeyword);
        if ($keyword === '' && $isContentRewrite && $context->article !== null) {
            $keyword = ContentProjectItemIdentity::normalize(
                $context->article->title !== null ? (string) $context->article->title : null,
            );
        }
        if ($keyword === '') {
            $keyword = 'rewrite';
        }

        $steps = [];

        // CREATE / recreate: tạo + gắn draft sớm để item có link click được (prompt history),
        // kể cả khi bước Prompt sau đó fail.
        if (
            $projectType !== SeoProjectTask::TYPE_REWRITE
            && $projectType !== SeoProjectTask::TYPE_IMPROVE
            && ! ($context->article instanceof SeoArticle)
        ) {
            $context = $this->ensureProjectTaskDraftArticle($context, $resolvedSiteId, $keyword);
        }

        try {
            ContentProjectCreateGenerationGuard::assertBeforeAi($context, $resolvedSiteId);
        } catch (\InvalidArgumentException $exception) {
            return [
                'success' => false,
                'article_id' => $context->article?->id,
                'steps' => [],
                'message' => $exception->getMessage(),
            ];
        }

        try {
            if ((string) ($context->variables['rerun_scope'] ?? '') === 'full') {
                return $this->runOutlineThenArticleForContext(
                    $this->withForcedAiRegenerate($context, 'outline'),
                    $resolvedSiteId,
                );
            }

            if ($projectType === SeoProjectTask::TYPE_REWRITE) {
                // «Tạo lại bài từ dàn ý» — content node only, không chạy lại outline.
                return $this->runArticleWritingForContext(
                    $context,
                    $task,
                    $resolvedSiteId,
                    $keyword,
                    ArticleWritingExecutionMode::ContentNode,
                    ArticleWritingSourceType::Outline,
                );
            }

            // First-run / CREATE: Publish graph đầy đủ.
            return $this->runArticleWritingForContext(
                $context,
                $task,
                $resolvedSiteId,
                $keyword,
                ArticleWritingExecutionMode::PublishGraph,
                ArticleWritingSourceType::Outline,
            );
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'article_id' => $context->article?->id,
                'steps' => $steps,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * FULL RERUN: explicit two-phase orchestration.
     *
     * Phase 1 — Outline/Vocabulary (skip content body writes)
     *         → durable Outline checkpoint on the article
     * Phase 2 — Explicit Writing via ArticleWritingExecutionService (not graph-downstream hope)
     *
     * @return array{
     *   success: bool,
     *   article_id: ?int,
     *   message: string,
     *   steps: list<array<string, mixed>>,
     *   persist_status?: string,
     *   error_code?: string,
     *   failed_step?: ?array{title: string, prompt_name: string, message: string, hook_key?: string}
     * }
     */
    public function runOutlineThenArticleForContext(TaskTestContext $context, int $siteId): array
    {
        $task = $this->resolvePublishWorkflowTask();

        $resolvedSiteId = (int) ($context->siteId ?? $siteId);
        $this->assertSiteAccessible($resolvedSiteId);
        $keyword = ContentProjectItemIdentity::topic(
            isset($context->variables['post_title']) ? (string) $context->variables['post_title'] : null,
            isset($context->variables['focus_keyword']) ? (string) $context->variables['focus_keyword'] : null,
        );
        if ($keyword === '') {
            $keyword = 'rewrite';
        }

        $projectType = SeoProjectTask::normalizeType((string) ($context->projectTaskType ?? ''));
        if (
            $projectType === SeoProjectTask::TYPE_CREATE
            && ! ($context->article instanceof SeoArticle)
        ) {
            $context = $this->ensureProjectTaskDraftArticle($context, $resolvedSiteId, $keyword);
        }
        try {
            ContentProjectCreateGenerationGuard::assertBeforeAi($context, $resolvedSiteId);
        } catch (\InvalidArgumentException $exception) {
            return [
                'success' => false,
                'article_id' => $context->article?->id,
                'steps' => [],
                'message' => $exception->getMessage(),
            ];
        }

        $outlineNodeId = $this->roleResolver->requireNodeId(
            $task,
            WorkflowExecutionRole::ArticleOutlineGenerate,
        );

        // ── PHASE 1: Outline + Vocabulary ONLY (WorkflowExecutionScope::OutlineVocabulary) ──
        $context = $this->withForcedAiRegenerate($context, 'outline');
        $phase1Steps = $this->runPhase1OutlineVocabularySteps($task, $context, $outlineNodeId);
        $phase1Steps = $this->stampPhaseOnSteps($phase1Steps, 'outline');

        $phase1Block = $this->summarizeBlockingPhase1Failure($phase1Steps);
        if ($phase1Block !== null) {
            return [
                'success' => false,
                'article_id' => $this->resolveArticleIdFromSteps($context, $phase1Steps),
                'steps' => $phase1Steps,
                'message' => $phase1Block['message'],
                'failed_step' => $phase1Block['failed_step'],
                'error_code' => 'full_rerun_outline_phase_failed',
            ];
        }

        $canonicalArtifact = $this->extractCanonicalOutlineFromSteps($phase1Steps);
        if ($canonicalArtifact === null || trim($canonicalArtifact) === '') {
            return [
                'success' => false,
                'article_id' => $this->resolveArticleIdFromSteps($context, $phase1Steps),
                'steps' => $phase1Steps,
                'message' => 'FULL RERUN: Outline phase did not produce a durable outline artifact.',
                'error_code' => 'full_rerun_outline_artifact_missing',
                'failed_step' => [
                    'title' => 'Outline checkpoint',
                    'prompt_name' => '',
                    'message' => 'Missing canonical outline artifact after Phase 1.',
                ],
            ];
        }

        $article = $this->resolveArticleFromWorkflow(
            $context,
            $phase1Steps,
            $resolvedSiteId,
            $keyword,
            $context->variables,
        );
        $persist = $this->articleOutlinePersist->persist($article, $canonicalArtifact);
        if (! ($persist['ok'] ?? false)) {
            return [
                'success' => false,
                'article_id' => (int) $article->id,
                'steps' => $phase1Steps,
                'message' => (string) ($persist['message'] ?? 'Không lưu được outline checkpoint.'),
                'error_code' => 'full_rerun_outline_checkpoint_failed',
                'failed_step' => [
                    'title' => 'Outline checkpoint',
                    'prompt_name' => '',
                    'message' => (string) ($persist['message'] ?? 'persist failed'),
                ],
            ];
        }

        $fresh = $article->fresh() ?? $article;
        $resolvedMarkdown = $this->articleOutlinePersist->resolveMarkdown($fresh);
        $artifactHash = hash('sha256', trim($canonicalArtifact));
        $resolvedHash = hash('sha256', trim($resolvedMarkdown));
        if ($resolvedMarkdown === '' || $resolvedHash !== $artifactHash) {
            return [
                'success' => false,
                'article_id' => (int) $fresh->id,
                'steps' => $phase1Steps,
                'message' => 'FULL RERUN: Outline checkpoint verification failed after persist.',
                'error_code' => 'full_rerun_outline_checkpoint_mismatch',
                'failed_step' => [
                    'title' => 'Outline checkpoint verify',
                    'prompt_name' => '',
                    'message' => 'resolveMarkdown hash !== Phase-1 artifact hash',
                ],
            ];
        }

        // Seed Writing exclusively from the new checkpoint (never stale article PromptResult).
        $context = $context->withArticle($fresh)->withVariables(array_merge($context->variables, [
            'article_writing_raw_input' => $canonicalArtifact,
            'direct_publish_outline_markdown' => $canonicalArtifact,
            'outline_artifact_hash' => $artifactHash,
            'force_ai_regenerate' => '1',
        ]));

        // ── PHASE 2: Explicit Writing (graph-independent) ──
        $writing = $this->runArticleWritingForContext(
            $context,
            $task,
            $resolvedSiteId,
            $keyword,
            ArticleWritingExecutionMode::ContentNode,
            ArticleWritingSourceType::Outline,
        );
        $writingSteps = $this->stampPhaseOnSteps(
            is_array($writing['steps'] ?? null) ? $writing['steps'] : [],
            'writing',
        );
        $mergedSteps = array_merge($phase1Steps, $writingSteps);

        if (! $this->writingPhaseExecuted($writing, $writingSteps)) {
            return [
                'success' => false,
                'article_id' => (int) ($writing['article_id'] ?? $fresh->id),
                'steps' => $mergedSteps,
                'message' => 'FULL RERUN: Outline checkpoint saved but Writing was not executed.',
                'error_code' => 'full_rerun_writing_not_executed',
                'persist_status' => $writing['persist_status'] ?? null,
                'failed_step' => [
                    'title' => 'Writing',
                    'prompt_name' => '',
                    'message' => 'article.content.generate was not executed after Outline checkpoint.',
                    'hook_key' => ArticleWritingExecutionService::HOOK_KEY,
                ],
            ];
        }

        return [
            'success' => (bool) ($writing['success'] ?? false),
            'article_id' => isset($writing['article_id']) ? (int) $writing['article_id'] : (int) $fresh->id,
            'steps' => $mergedSteps,
            'message' => (string) ($writing['message'] ?? 'Đã chạy FULL RERUN (Outline checkpoint + Writing).'),
            'persist_status' => $writing['persist_status'] ?? null,
            'error_code' => $writing['error_code'] ?? null,
            'failed_step' => $writing['failed_step'] ?? null,
            'source_type' => $writing['source_type'] ?? null,
            'prompt_owner_type' => $writing['prompt_owner_type'] ?? null,
            'outline_checkpoint_hash' => $artifactHash,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function stampPhaseOnSteps(array $steps, string $phase): array
    {
        $out = [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $step['full_rerun_phase'] = $phase;
            $out[] = $step;
        }

        return $out;
    }

    /**
     * Phase-1 expected skips (content/save_article/not_reachable outside outline branch) are not failures.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return null|array{message: string, failed_step: ?array{title: string, prompt_name: string, message: string, hook_key?: string}}
     */
    private function summarizeBlockingPhase1Failure(array $steps): ?array
    {
        $nonBlocking = [
            'not_reachable',
            'skipped_scope',
            \Omnichannel\Addons\AiPrompt\Support\WorkflowPublishContentEvidence::SKIP_REASON_OUTLINE_ONLY_SCOPE,
            'outline_vocabulary_scope',
        ];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $status = strtolower(trim((string) ($step['status'] ?? '')));
            $skip = strtolower(trim((string) ($step['skip_reason'] ?? '')));
            if (in_array($status, ['skipped', 'not_reachable'], true) && in_array($skip, $nonBlocking, true)) {
                continue;
            }
            if (! in_array($status, ['failed', 'blocked', 'error'], true)) {
                continue;
            }

            $failure = $this->summarizeWorkflowFailure([$step]);

            return [
                'message' => $failure['message'],
                'failed_step' => $failure['failed_step'],
            ];
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function extractCanonicalOutlineFromSteps(array $steps): ?string
    {
        foreach (array_reverse($steps) as $step) {
            if (! is_array($step)) {
                continue;
            }
            $fromField = trim((string) ($step['outline_markdown'] ?? ''));
            if ($fromField !== '' && $this->outlineResolver->isValidArtifact($fromField)) {
                $vocab = trim((string) ($step['vocabulary_markdown'] ?? ''));
                if ($vocab !== '') {
                    $merged = $fromField."\n".$vocab;
                    if ($this->outlineResolver->isValidArtifact($merged)) {
                        return $merged;
                    }
                }

                return $fromField;
            }

            $extracted = $this->extractOutlineArtifactFromStep($step);
            if ($extracted !== null && trim($extracted) !== '') {
                return $extracted;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $writingResult
     * @param  list<array<string, mixed>>  $writingSteps
     */
    private function writingPhaseExecuted(array $writingResult, array $writingSteps): bool
    {
        return $this->hasWritingInvocationEvidence($writingResult, $writingSteps);
    }

    /**
     * Writing counts as executed only with invocation evidence — not persist_status alone.
     *
     * @param  array<string, mixed>  $writingResult
     * @param  list<array<string, mixed>>  $writingSteps
     */
    private function hasWritingInvocationEvidence(array $writingResult, array $writingSteps): bool
    {
        foreach ($writingSteps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $status = strtolower(trim((string) ($step['status'] ?? '')));
            if (! in_array($status, ['completed', 'success', 'failed', 'blocked'], true)) {
                continue;
            }

            $hook = strtolower(trim((string) ($step['hook_key'] ?? '')));
            if ($hook === ArticleWritingExecutionService::HOOK_KEY) {
                return true;
            }

            $role = WorkflowExecutionRole::tryFromMixed($step['execution_role'] ?? null);
            if ($role === WorkflowExecutionRole::ArticleContentGenerate) {
                return true;
            }

            if (($step['artifact_type'] ?? '') === 'article_content') {
                return true;
            }

            $resultId = (int) ($step['result_id'] ?? 0);
            $promptResultIds = is_array($step['prompt_result_ids'] ?? null)
                ? $step['prompt_result_ids']
                : [];
            if ($resultId > 0 || $promptResultIds !== []) {
                // History/result evidence only counts when this step looks like Writing.
                if ($hook === ArticleWritingExecutionService::HOOK_KEY
                    || $role === WorkflowExecutionRole::ArticleContentGenerate
                    || ($step['artifact_type'] ?? '') === 'article_content'
                ) {
                    return true;
                }
            }
        }

        if ((int) ($writingResult['result_id'] ?? 0) > 0) {
            return true;
        }
        if ((int) ($writingResult['prompt_result_id'] ?? 0) > 0) {
            return true;
        }

        // persist_status alone (including failed / ignored_stale) is insufficient.
        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array{success: bool, article_id: ?int, message: string, steps: list<array<string, mixed>>}
     */
    private function finalizeWorkflowGraphRun(
        TaskTestContext $context,
        SeoTask $task,
        int $resolvedSiteId,
        string $keyword,
        array $steps,
        bool $requireContent = true,
    ): array {
        $failed = collect($steps)->contains(
            static fn (array $step): bool => in_array((string) ($step['status'] ?? ''), ['failed', 'blocked'], true),
        );

        if ($failed) {
            $failure = $this->summarizeWorkflowFailure($steps);

            return [
                'success' => false,
                'article_id' => $this->resolveArticleIdFromSteps($context, $steps),
                'steps' => $steps,
                'message' => $failure['message'],
                'failed_step' => $failure['failed_step'],
            ];
        }

        $article = $this->resolveArticleFromWorkflow(
            $context,
            $steps,
            $resolvedSiteId,
            $keyword,
            $context->variables,
        );
        $this->workflowRunner->applyParsedMetaFromSteps($article, $steps);
        $this->syncFocusKeywordFromContext($article, $resolvedSiteId, $context);

        $evidence = \Omnichannel\Addons\AiPrompt\Support\WorkflowPublishContentEvidence::evaluate(
            $steps,
            $article->fresh() ?? $article,
            requireContent: $requireContent,
        );
        if (! $evidence['ok']) {
            $steps[] = [
                'node_id' => 'content-evidence-guard',
                'type' => 'guard',
                'title' => 'Content evidence',
                'status' => 'failed',
                'message' => $evidence['message'],
                'skip_reason' => $evidence['code'],
                'error_code' => $evidence['code'],
            ];

            return [
                'success' => false,
                'article_id' => (int) $article->id,
                'steps' => $steps,
                'message' => $evidence['message'],
                'failed_step' => [
                    'title' => 'Content evidence',
                    'prompt_name' => '',
                    'message' => $evidence['message'],
                ],
                'error_code' => $evidence['code'],
            ];
        }

        return [
            'success' => true,
            'article_id' => (int) $article->id,
            'steps' => $steps,
            'message' => 'Đã chạy quy trình và tạo/cập nhật bài.',
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function extractOutlineArtifactFromStep(array $step): ?string
    {
        $outputs = is_array($step['outputs'] ?? null) ? $step['outputs'] : [];
        // Prefer marked total / out_main — section ports may be marker-stripped.
        $candidates = [
            $step['outline_markdown'] ?? null,
            $outputs['total'] ?? null,
            $outputs['out_main'] ?? null,
            $step['output'] ?? null,
            $step['result'] ?? null,
            $outputs['task_1_outline'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $raw = trim((string) $candidate);
            if ($raw !== '' && $this->outlineResolver->isValidArtifact($raw)) {
                return $raw;
            }
            // Có thể outline + vocabulary đã ghép sẵn.
            if ($raw !== '' && isset($outputs['task_2_vocabulary'])) {
                $merged = $raw."\n".trim((string) $outputs['task_2_vocabulary']);
                if ($this->outlineResolver->isValidArtifact($merged)) {
                    return $merged;
                }
            }
        }

        $fromParsed = $this->outlineResolver->tryResolveFromRawArtifact(
            trim((string) ($step['ai_output'] ?? $step['message'] ?? '')),
        );

        return $fromParsed?->rawArtifact;
    }

    /**
     * @return array{success: bool, article_id: ?int, message: string, steps: list<array<string, mixed>>}
     */
    private function runArticleWritingForContext(
        TaskTestContext $context,
        SeoTask $task,
        int $resolvedSiteId,
        string $keyword,
        ArticleWritingExecutionMode $mode,
        ArticleWritingSourceType $sourceType,
        ?string $contentNodeId = null,
    ): array {
        if ($contentNodeId === null && $mode === ArticleWritingExecutionMode::ContentNode) {
            $contentNodeId = $this->roleResolver->requireNodeId(
                $task,
                WorkflowExecutionRole::ArticleContentGenerate,
            );
        }

        $variables = $context->variables;
        try {
            ContentProjectCreateGenerationGuard::assertBeforeAi($context, $resolvedSiteId);
        } catch (\InvalidArgumentException $exception) {
            return [
                'success' => false,
                'article_id' => $context->article?->id,
                'steps' => [],
                'message' => $exception->getMessage(),
            ];
        }

        $rawInput = trim((string) (
            $variables['article_writing_raw_input']
            ?? (! empty($variables['article_writing_formatted']) ? '' : ($variables['input'] ?? ''))
        ));

        $writing = new ArticleWritingInput(
            sourceType: $sourceType,
            input: $rawInput,
            title: trim((string) ($variables['post_title'] ?? '')),
            keyword: trim((string) ($variables['focus_keyword'] ?? $keyword)),
            description: trim((string) ($variables['secondary_description'] ?? '')),
            articleId: $context->article !== null ? (int) $context->article->getKey() : null,
            runId: isset($variables['source_run_id']) ? (int) $variables['source_run_id'] : null,
            runItemId: isset($variables['source_run_item_id']) ? (int) $variables['source_run_item_id'] : null,
            sourcePromptResultId: isset($variables['source_prompt_result_id'])
                ? (int) $variables['source_prompt_result_id']
                : null,
            metadata: [
                'article_length' => $variables['article_length'] ?? null,
                'outline_artifact_hash' => $variables['outline_artifact_hash'] ?? null,
                'article_source_artifact_hash' => $variables['article_source_artifact_hash'] ?? null,
                'execution_role' => $mode === ArticleWritingExecutionMode::ContentNode
                    ? WorkflowExecutionRole::ArticleContentGenerate->value
                    : null,
                'workflow_execution_snapshot' => $this->workflowSnapshotBuilder->fromTask($task)->toArray(),
                'content_node_id' => $contentNodeId,
            ],
        );

        $contentFound = $contentNodeId !== null
            ? $this->roleResolver->findNode($task, WorkflowExecutionRole::ArticleContentGenerate)
            : null;

        $wfSnap = $this->workflowSnapshotBuilder->fromTask($task)->toArray();
        $variables['workflow_execution_snapshot'] = $wfSnap;
        $variables['flow_data_hash'] = $wfSnap['flow_data_hash'] ?? null;
        if ($contentNodeId !== null) {
            $variables['content_node_id'] = $contentNodeId;
        }

        $result = $this->articleWriting->execute(
            $writing,
            new ArticleWritingExecutionContext(
                mode: $mode,
                promptOwnerType: ArticleWritingPromptOwnerType::WorkflowNode,
                siteId: $resolvedSiteId,
                promptId: $contentFound['prompt_id'] ?? null,
                promptOwnerId: $contentNodeId,
                workflowTask: $task,
                contentNodeId: $contentNodeId,
                taskContext: $context,
                expectedUpdatedAt: $context->article?->updated_at?->toIso8601String(),
                baseVariables: $variables,
            ),
        );

        $payload = $result->toLegacyWorkflowArray();
        $steps = $payload['steps'];

        if (! $result->success) {
            $failure = $this->summarizeWorkflowFailure($steps);

            return [
                'success' => false,
                'article_id' => $payload['article_id'] ?? $this->resolveArticleIdFromSteps($context, $steps),
                'message' => $failure['message'] !== 'Quy trình có bước lỗi.'
                    ? $failure['message']
                    : $result->message,
                'failed_step' => $failure['failed_step'],
                'steps' => $steps,
                'persist_status' => $result->persistStatus,
            ];
        }

        if ($result->persistStatus === \Omnichannel\Addons\Content\Support\ArticleWritingExecutionResult::PERSIST_IGNORED_STALE) {
            return [
                'success' => true,
                'article_id' => $payload['article_id'],
                'steps' => $steps,
                'message' => $result->message,
                'persist_status' => $result->persistStatus,
            ];
        }

        $article = $this->resolveArticleFromWorkflow(
            $context,
            $steps,
            $resolvedSiteId,
            $keyword,
            $context->variables,
        );
        $this->workflowRunner->applyParsedMetaFromSteps($article, $steps);
        $this->syncFocusKeywordFromContext($article, $resolvedSiteId, $context);

        return [
            'success' => true,
            'article_id' => (int) $article->id,
            'steps' => $steps,
            'message' => $result->message !== '' ? $result->message : 'Đã chạy quy trình và tạo/cập nhật bài.',
            'persist_status' => $result->persistStatus,
            'source_type' => $result->sourceType->value,
            'prompt_owner_type' => $result->promptOwnerType->value,
            'warning_code' => $payload['warning_code'] ?? $result->historyMetadata['warning_code'] ?? null,
            'warning_message' => $payload['warning_message'] ?? $result->historyMetadata['warning_message'] ?? null,
            'length_validation' => $payload['length_validation'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function resolveArticleIdFromSteps(TaskTestContext $context, array $steps): ?int
    {
        foreach (array_reverse($steps) as $step) {
            $articleId = (int) ($step['article_id'] ?? 0);
            if ($articleId > 0) {
                return $articleId;
            }
        }

        return $context->article instanceof SeoArticle
            ? (int) $context->article->id
            : null;
    }

    /**
     * Ensure Content Project item has a live draft article before workflow runs.
     * Attaches task.article_id immediately so Ops UI link is clickable.
     */
    private function ensureProjectTaskDraftArticle(
        TaskTestContext $context,
        int $siteId,
        string $keyword,
    ): TaskTestContext {
        $originId = ProjectTaskOriginVariables::read($context->variables);
        if ($originId === null) {
            return $context;
        }

        $task = SeoProjectTask::query()->find($originId);
        if ($task instanceof SeoProjectTask) {
            $existingId = (int) ($task->article_id ?? 0);
            if ($existingId > 0) {
                $localId = LocalArticleAssociationGuard::resolveLocalArticleId(
                    $existingId,
                    $siteId > 0 ? $siteId : null,
                );
                if ($localId !== null) {
                    $existing = SeoArticle::query()->find($localId);
                    if ($existing instanceof SeoArticle) {
                        return $context->withArticle($existing);
                    }
                }
            }
        }

        $variables = $context->variables;
        if ($context->postType !== null && $context->postType !== '') {
            $variables['_project_post_type'] = $context->postType;
        }

        $article = $this->createDraftArticle($siteId, $keyword, $variables, []);
        $task?->refresh();

        return $context->withArticle($article);
    }

    /**
     * @param  array<string, string>  $variables
     * @param  list<array<string, mixed>>  $steps
     */
    private function resolveArticleFromWorkflow(
        TaskTestContext $context,
        array $steps,
        int $siteId,
        string $keyword,
        array $variables,
    ): SeoArticle {
        foreach ($steps as $step) {
            if (($step['type'] ?? '') === 'action' && is_numeric($step['article_id'] ?? null)) {
                $stepArticleId = (int) $step['article_id'];
                $localId = LocalArticleAssociationGuard::resolveLocalArticleId(
                    $stepArticleId,
                    $siteId > 0 ? $siteId : null,
                );
                if ($localId === null) {
                    continue;
                }
                $article = SeoArticle::query()->find($localId);
                if ($article instanceof SeoArticle) {
                    $this->ensureArticlePostType($article, $context);

                    return $article;
                }
            }
        }

        if ($context->article instanceof SeoArticle) {
            $this->ensureArticlePostType($context->article, $context);

            return $context->article;
        }

        if ($context->postType !== null && $context->postType !== '') {
            $variables['_project_post_type'] = $context->postType;
        }

        return $this->createDraftArticle($siteId, $keyword, $variables, $steps);
    }

    /**
     * @param  array<string, string>  $variables
     * @param  list<array<string, mixed>>  $steps
     */
    private function createDraftArticle(int $siteId, string $keyword, array $variables, array $steps): SeoArticle
    {
        $title = ContentProjectItemIdentity::normalize(
            isset($variables['post_title']) ? (string) $variables['post_title'] : null,
        );
        if ($title === '') {
            // Provisional article title from topic (keyword) until AI final title lands.
            $title = ContentProjectItemIdentity::normalize(
                isset($variables['topic']) ? (string) $variables['topic'] : $keyword,
            );
        }

        $postType = SeoProjectTask::normalizePostType(
            (string) ($variables['_project_post_type'] ?? 'article'),
        );

        $originId = ProjectTaskOriginVariables::read($variables);
        $originType = $originId !== null
            ? ArticleCreateOriginResolver::ORIGIN_SEO_PROJECT_TASK
            : null;

        // Do not invent focus_keyword from title — title-only items keep keyword empty.
        $focusKeyword = ContentProjectItemIdentity::normalize(
            isset($variables['focus_keyword']) ? (string) $variables['focus_keyword'] : null,
        );
        $correlationId = Str::uuid()->toString();

        $input = [
            'site_id' => $siteId,
            'title' => $title !== '' ? $title : $keyword,
            'keyword' => $focusKeyword,
            'post_type' => $postType,
            'language' => 'vi',
            'origin_type' => $originType,
            'origin_id' => $originId,
            'focus_keyword' => $focusKeyword,
            'steps_count' => count($steps),
        ];

        $existingByOrigin = null;
        $skipOriginReuse = false;
        if ($originType === ArticleCreateOriginResolver::ORIGIN_SEO_PROJECT_TASK && $originId !== null) {
            $originTask = SeoProjectTask::query()->find($originId);
            if ($originTask instanceof SeoProjectTask && (int) ($originTask->article_id ?? 0) <= 0) {
                $skipOriginReuse = true;
            }
        }
        if (! $skipOriginReuse) {
            $existingByOrigin = $this->originResolver->findExisting(
                $originType,
                $originId,
                $siteId,
                $postType,
            );
        }

        try {
            $normalized = $this->articleCreateBridge->run(
                input: $input,
                legacyWrite: function () use (
                    $siteId,
                    $keyword,
                    $title,
                    $postType,
                    $focusKeyword,
                    $steps,
                    $originType,
                    $originId,
                    $existingByOrigin,
                ): array {
                    if (is_array($existingByOrigin)) {
                        return $existingByOrigin;
                    }

                    return $this->legacyCreateDraftArticle(
                        $siteId,
                        $keyword,
                        $title,
                        $postType,
                        $focusKeyword,
                        $steps,
                        $originType,
                        $originId,
                    );
                },
                actionWrite: function () use (
                    $siteId,
                    $keyword,
                    $title,
                    $postType,
                    $focusKeyword,
                    $steps,
                    $originType,
                    $originId,
                    $correlationId,
                ): ActionResult {
                    $result = $this->actionRunner->run(
                        'article.create',
                        ActionContext::fromArray([
                            'origin' => 'migration.project_article_create',
                            'actor_id' => auth()->id() !== null ? (int) auth()->id() : null,
                            'site_id' => $siteId,
                            'correlation_id' => $correlationId,
                        ]),
                        [
                            'site_id' => $siteId,
                            'title' => $title,
                            'keyword' => $keyword !== '' ? $keyword : $focusKeyword,
                            'post_type' => $postType,
                            'language' => 'vi',
                            'origin_type' => $originType,
                            'origin_id' => $originId,
                        ],
                    );

                    if (! $result->success) {
                        return $result;
                    }

                    $articleId = (int) ($result->output['article_id'] ?? 0);
                    $deduplicated = (bool) ($result->output['deduplicated'] ?? false);
                    if ($articleId > 0 && ! $deduplicated) {
                        $article = SeoArticle::query()->find($articleId);
                        if ($article instanceof SeoArticle) {
                            $this->stampCreateArticleTaskRunMeta($article, $keyword, $steps);
                            if ($focusKeyword !== '' && $focusKeyword !== $keyword) {
                                $article->articleMetas()->updateOrCreate(
                                    ['meta_key' => 'seo_focus_keyword'],
                                    ['meta_value' => $focusKeyword],
                                );
                            }
                        }
                    }

                    return $result;
                },
                existingByOrigin: $existingByOrigin,
                correlationId: $correlationId,
            );
        } catch (AutomationMigrationWriteException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
        }

        $articleId = (int) (is_array($normalized) ? ($normalized['article_id'] ?? 0) : 0);
        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            throw new \RuntimeException('Article create bridge returned invalid article_id.');
        }

        return $article;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array{
     *   article_id: int,
     *   site_id: int,
     *   post_type: string,
     *   status: string,
     *   title: string,
     *   deduplicated: bool
     * }
     */
    private function legacyCreateDraftArticle(
        int $siteId,
        string $keyword,
        string $title,
        string $postType,
        string $focusKeyword,
        array $steps,
        ?string $originType,
        ?int $originId,
    ): array {
        $slug = Str::slug($keyword);
        if ($slug === '') {
            $slug = Str::slug($title);
        }

        $classification = ArticleContentClassification::fromTaskPostType($postType);

        $article = SeoArticle::query()->create([
            'site_id' => $siteId,
            'user_id' => auth()->id(),
            'title' => $title,
            'slug' => $slug !== '' ? $slug : null,
            'status' => 'draft',
            'body' => '',
            'language' => 'vi',
            'parent_id' => $classification['parent_id'],
        ]);

        ArticleContentClassification::persist($article, $classification);

        $this->attachKeyword($article, $keyword);

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_focus_keyword'],
            ['meta_value' => $focusKeyword !== '' ? $focusKeyword : $keyword],
        );

        $this->stampCreateArticleTaskRunMeta($article, $keyword, $steps);

        if ($originType !== null && $originType !== '' && $originId !== null && $originId > 0) {
            $this->originResolver->persistOriginMeta($article, $originType, $originId);
            if ($originType === ArticleCreateOriginResolver::ORIGIN_SEO_PROJECT_TASK) {
                $this->originResolver->attachToProjectTaskIfNeeded($originId, (int) $article->id);
            }
        }

        return [
            'article_id' => (int) $article->id,
            'site_id' => $siteId,
            'post_type' => $postType,
            'status' => 'draft',
            'title' => $title,
            'deduplicated' => false,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function stampCreateArticleTaskRunMeta(SeoArticle $article, string $keyword, array $steps): void
    {
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'create_article_task_run'],
            ['meta_value' => json_encode([
                'keyword' => $keyword,
                'steps_count' => count($steps),
                'ran_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE)],
        );
    }

    private function ensureArticlePostType(SeoArticle $article, TaskTestContext $context): void
    {
        // Rewrite: giữ nguyên type bài có sẵn — không ghi đè product/article.
        if ($context->projectTaskType === SeoProjectTask::TYPE_REWRITE) {
            return;
        }

        if ($context->postType === null || trim((string) $context->postType) === '') {
            return;
        }

        $postType = SeoProjectTask::normalizePostType($context->postType);
        $desired = ArticleContentClassification::fromTaskPostType($postType);
        $current = ArticleContentClassification::for($article);

        if (
            $current->contentType() === $desired['content_type']
            && $current->isTerm() === $desired['wp_is_term']
        ) {
            return;
        }

        ArticleContentClassification::persist($article, $desired);
    }

    private function attachKeyword(SeoArticle $article, string $phrase): void
    {
        $normalized = mb_strtolower(trim($phrase));
        if ($normalized === '') {
            return;
        }

        KeywordFocusAttach::attachMainKeyword(
            $article,
            (int) $article->site_id,
            trim($phrase),
        );
    }

    private function syncFocusKeywordFromContext(SeoArticle $article, int $siteId, TaskTestContext $context): void
    {
        $focusKeyword = ContentProjectItemIdentity::normalize(
            isset($context->variables['focus_keyword']) ? (string) $context->variables['focus_keyword'] : null,
        );
        if ($focusKeyword === '') {
            return;
        }

        KeywordFocusAttach::syncMainKeyword(
            $article,
            $siteId,
            auth()->id() !== null ? (int) auth()->id() : 0,
            $focusKeyword,
        );
    }

    /**
     * Explicit step rerun must never short-circuit on existing body/outline.
     */
    /**
     * Phase-1 graph run — OutlineVocabulary scope only. Hook for behavioral tests.
     *
     * @return list<array<string, mixed>>
     */
    protected function runPhase1OutlineVocabularySteps(
        SeoTask $task,
        TaskTestContext $context,
        string $outlineNodeId,
    ): array {
        return $this->workflowRunner->runFromNodeId(
            $task,
            $context,
            $outlineNodeId,
            seedOutlineFromArticle: false,
            executionScope: WorkflowExecutionScope::OutlineVocabulary,
        );
    }

    /**
     * Active publish workflow SeoTask (hook for tests).
     */
    protected function resolvePublishWorkflowTask(): SeoTask
    {
        $taskId = $this->settings->getPublishArticleTaskId();
        if ($taskId === null) {
            throw new \InvalidArgumentException(
                'Chưa cấu hình quy trình Đăng bài viết. Vào SEO → Cài đặt → Quy trình để chọn task.',
            );
        }
        $task = SeoTask::query()->find($taskId);
        if (! $task instanceof SeoTask || ! $task->is_active) {
            throw new \InvalidArgumentException('Quy trình Đăng bài viết không khả dụng.');
        }

        return $task;
    }

    private function withForcedAiRegenerate(TaskTestContext $context, string $fromStep): TaskTestContext
    {
        return $context->withVariables(array_merge($context->variables, [
            'force_ai_regenerate' => '1',
            'rerun_from_step' => $fromStep,
        ]));
    }

    protected function assertSiteAccessible(int $siteId): void
    {
        if (! SeoAccessControl::canAccessSite($siteId)) {
            throw new \InvalidArgumentException('Website không hợp lệ hoặc bạn không có quyền.');
        }
    }

    private function syncDomainLinkListKeywords(int $siteId): void
    {
        $site = Site::query()->find($siteId);
        if ($site instanceof Site) {
            $this->linkListSync->syncFromStoredContext($site);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array{message: string, failed_step: ?array{title: string, prompt_name: string, message: string}}
     */
    private function summarizeWorkflowFailure(array $steps): array
    {
        $failed = collect($steps)
            ->first(fn (array $step): bool => (string) ($step['status'] ?? '') === 'failed');

        if (! is_array($failed)) {
            return [
                'message' => 'Quy trình có bước lỗi.',
                'failed_step' => null,
            ];
        }

        $stepMessage = trim((string) ($failed['message'] ?? ''));
        $stepTitle = trim((string) ($failed['title'] ?? ''));
        $promptName = trim((string) ($failed['prompt_name'] ?? ''));
        $hookKey = trim((string) ($failed['hook_key'] ?? ''));

        // Stage label must follow execution hook metadata — never leave a writing
        // failure prefixed as Outline when hook is article.content.*.
        if ($hookKey !== '' && str_contains($hookKey, 'content.')
            && str_starts_with(mb_strtolower($stepMessage), 'outline generation failed')
        ) {
            $stepMessage = 'Writing generation failed: '.trim(substr($stepMessage, strlen('Outline generation failed:')));
        }

        $labelParts = array_values(array_filter([$stepTitle, $promptName]));
        if ($hookKey !== '') {
            $labelParts[] = $hookKey;
        }
        $prefix = $labelParts !== [] ? implode(' — ', $labelParts).': ' : '';

        return [
            'message' => $prefix.($stepMessage !== '' ? $stepMessage : 'Quy trình có bước lỗi.'),
            'failed_step' => [
                'title' => $stepTitle,
                'prompt_name' => $promptName,
                'message' => $stepMessage,
                'hook_key' => $hookKey,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function parseKeywords(string $raw): array
    {
        $parts = preg_split('/[\r\n,;]+/u', $raw) ?: [];
        $keywords = [];

        foreach ($parts as $part) {
            $phrase = trim($part);
            if ($phrase === '') {
                continue;
            }
            $keywords[] = $phrase;
        }

        return array_values(array_unique($keywords));
    }

    /**
     * @return array<int|string, string>
     */
    public function taskOptionsForSettings(?int $includeTaskId = null): array
    {
        $query = SeoTask::query()->where('is_active', true)->orderBy('name');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        $options = $query
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [
                (int) $id => trim((string) $name) !== '' ? trim((string) $name) : ('#'.(int) $id),
            ])
            ->all();

        $id = (int) ($includeTaskId ?? 0);
        if ($id > 0 && ! array_key_exists($id, $options)) {
            $taskQuery = SeoTask::query()->whereKey($id);
            if (SeoAccessControl::shouldScopeToAccountOwner()) {
                $taskQuery->where('user_id', SeoAccessControl::accountSiteOwnerId());
            }
            $task = $taskQuery->first();
            if ($task !== null) {
                $name = trim((string) ($task->name ?? ''));
                $label = $name !== '' ? $name : '#'.$id;
                if (! (bool) ($task->is_active ?? false)) {
                    $label .= ' ('.(string) __('seo-content-ai::filament.settings_workflows.task_inactive').')';
                }
                $options[$id] = $label;
            } else {
                $options[$id] = '#'.$id;
            }
        }

        return $options;
    }

    public function taskLabel(mixed $taskId): ?string
    {
        $id = (int) $taskId;
        if ($id <= 0) {
            return null;
        }

        $task = SeoTask::query()->find($id);
        if ($task === null) {
            return '#'.$id;
        }

        $name = trim((string) ($task->name ?? ''));

        return $name !== '' ? $name : '#'.$id;
    }
}
