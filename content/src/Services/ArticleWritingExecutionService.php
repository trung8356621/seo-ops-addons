<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Enums\ArticleWritingExecutionMode;
use Omnichannel\Addons\AiPrompt\Enums\ArticleWritingPromptOwnerType;
use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBindingRunner;
use Omnichannel\Addons\Content\Services\ArticleWriting\BriefArticleWritingSourceProvider;
use Omnichannel\Addons\Content\Services\ArticleWriting\ExistingArticleWritingSourceProvider;
use Omnichannel\Addons\Content\Services\ArticleWriting\OutlineArticleWritingSourceProvider;
use Omnichannel\Addons\AiPrompt\Contracts\ArticleBodyPublishPort;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptBindingResolver;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionRoleResolver;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionContext;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionResult;
use Omnichannel\Addons\Content\Support\ArticleWritingInput;
use Omnichannel\Addons\Content\Support\ArticleGenerationLengthValidator;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use Omnichannel\Addons\ContentProjects\Support\WorkflowExecutionSnapshot;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

/**
 * Entry duy nhất cho article.content.generate (outline / existing_article / brief).
 * Không biết Livewire / queue / UI.
 */
class ArticleWritingExecutionService
{
    public const HOOK_KEY = 'article.content.generate';

    public function __construct(
        private readonly OutlineArticleWritingSourceProvider $outlineProvider,
        private readonly ExistingArticleWritingSourceProvider $existingProvider,
        private readonly BriefArticleWritingSourceProvider $briefProvider,
        private readonly ArticleWritingInputFormatter $formatter,
        private readonly PromptBindingResolver $promptBindingResolver,
        private readonly PromptHookBindingRunner $hookBinding,
        private readonly ArticleBodyPublishPort $publisher,
        private readonly TaskWorkflowTestRunner $workflowRunner,
        private readonly SeoCreateArticleSettingsService $settings,
        private readonly SeoPromptSettingsService $promptSettings,
        private readonly WorkflowExecutionRoleResolver $roleResolver,
    ) {}

    public function execute(
        ArticleWritingInput $input,
        ArticleWritingExecutionContext $context,
    ): ArticleWritingExecutionResult {
        $this->assertValidSourceType($input->sourceType);
        $context->assertOwnerXor();

        if ($context->useRetrySnapshot) {
            return $this->executeWithRetrySnapshot($input, $context);
        }

        $article = $context->taskContext?->article;
        $variables = array_merge(
            $context->baseVariables,
            is_array($context->taskContext?->variables) ? $context->taskContext->variables : [],
        );
        $variables = \Omnichannel\Addons\AiPrompt\Support\ArticleGenerationModePreference::stampIntoVariables(
            $variables,
            (int) (auth()->id() ?? 0) ?: null,
        );

        if ($context->generationStrategy !== null && trim($context->generationStrategy) !== '') {
            $variables['generation_strategy'] = trim($context->generationStrategy);
        }

        // First-run PublishGraph: outline artifact chưa có — để workflow edge cung cấp ở content node.
        $deferOutline = $context->mode === ArticleWritingExecutionMode::PublishGraph
            && $input->sourceType === ArticleWritingSourceType::Outline
            && trim($input->input) === '';

        if ($deferOutline) {
            $writing = $input;
            $variables['article_writing_source_type'] = ArticleWritingSourceType::Outline->value;
            $variables['source_type'] = ArticleWritingSourceType::Outline->value;
        } else {
            // Rerun / content / direct: resolve source lại từ provider.
            $writing = $this->resolveSource($input->sourceType, $variables, $article, $input);
            $variables = $this->formatter->applyToVariables($writing, $variables);
        }
        $variables = $this->applyArticleLength($variables, $context, retry: false);
        $owner = $this->resolvePromptOwner($context, $variables);

        $variables['prompt_owner_type'] = $owner['type']->value;
        $variables['prompt_owner_id'] = $owner['owner_id'];
        $variables['prompt_id'] = $owner['prompt_id'];
        $variables['hook_key'] = self::HOOK_KEY;

        $taskContext = $this->stampTaskContext($context->taskContext, $variables, $writing);

        $policy = \Omnichannel\Addons\AiPrompt\Support\AiCostPolicy::tryFromMixed(
            $variables[\Omnichannel\Addons\AiPrompt\Support\AiCostPolicy::SETTING_KEY] ?? null,
        );

        return \Omnichannel\Addons\AiPrompt\Support\AiCostPolicyScope::run(
            $policy,
            fn (): ArticleWritingExecutionResult => match ($context->mode) {
                ArticleWritingExecutionMode::PublishGraph => $this->executePublishGraph(
                    $writing,
                    $context,
                    $taskContext,
                    $owner,
                ),
                ArticleWritingExecutionMode::ContentNode => $this->executeContentNode(
                    $writing,
                    $context,
                    $taskContext,
                    $owner,
                ),
                ArticleWritingExecutionMode::DirectGenerate => $this->executeDirectGenerate(
                    $writing,
                    $context,
                    $taskContext,
                    $owner,
                    $variables,
                ),
            },
        );
    }

    /**
     * Retry same execution: giữ snapshot source/prompt/length — không đọc Settings mới.
     */
    private function executeWithRetrySnapshot(
        ArticleWritingInput $input,
        ArticleWritingExecutionContext $context,
    ): ArticleWritingExecutionResult {
        $snapshot = $context->retrySnapshot;
        if (! $this->retrySnapshotIsComplete($snapshot, $context)) {
            return $this->fail(
                $input,
                [
                    'type' => $context->promptOwnerType,
                    'prompt_id' => $context->promptId,
                    'owner_id' => $context->promptOwnerId,
                    'prompt' => null,
                ],
                'Không thể thử lại lần chạy cũ. Hãy chọn «Chạy lại bằng cấu hình hiện tại».',
            );
        }

        $sourceType = ArticleWritingSourceType::tryFromMixed(
            $snapshot['article_writing_source_type'] ?? $snapshot['source_type'] ?? null,
        );
        if (! $sourceType instanceof ArticleWritingSourceType) {
            return $this->fail(
                $input,
                [
                    'type' => $context->promptOwnerType,
                    'prompt_id' => $context->promptId,
                    'owner_id' => $context->promptOwnerId,
                    'prompt' => null,
                ],
                'Không thể thử lại lần chạy cũ. Hãy chọn «Chạy lại bằng cấu hình hiện tại».',
            );
        }

        $this->assertValidSourceType($sourceType);

        $ownerType = ArticleWritingPromptOwnerType::tryFrom(
            (string) ($snapshot['prompt_owner_type'] ?? ''),
        );
        if (! $ownerType instanceof ArticleWritingPromptOwnerType) {
            return $this->fail(
                $input,
                [
                    'type' => $context->promptOwnerType,
                    'prompt_id' => $context->promptId,
                    'owner_id' => $context->promptOwnerId,
                    'prompt' => null,
                ],
                'Không thể thử lại lần chạy cũ. Hãy chọn «Chạy lại bằng cấu hình hiện tại».',
            );
        }

        $promptId = isset($snapshot['prompt_id']) ? (int) $snapshot['prompt_id'] : null;
        $ownerId = isset($snapshot['prompt_owner_id'])
            ? (string) $snapshot['prompt_owner_id']
            : null;

        $variables = array_merge($context->baseVariables, $snapshot);
        $variables['article_writing_source_type'] = $sourceType->value;
        $variables['source_type'] = $sourceType->value;
        $variables['prompt_owner_type'] = $ownerType->value;
        $variables['prompt_owner_id'] = $ownerId;
        $variables['prompt_id'] = $promptId;
        $variables['hook_key'] = self::HOOK_KEY;
        $variables['retry_or_rerun'] = 'retry';
        if (isset($snapshot['article_length'])) {
            $variables['article_length'] = $snapshot['article_length'];
        }

        $writing = $input;
        if (($variables['article_writing_raw_input'] ?? '') === '' && $input->input !== '') {
            $variables['article_writing_raw_input'] = $input->input;
        }
        $variables = $this->formatter->applyToVariables($writing, $variables);

        $contentNodeId = trim((string) ($snapshot['content_node_id'] ?? ''));
        if ($contentNodeId === '') {
            $wfSnap = WorkflowExecutionSnapshot::tryFromArray(
                $snapshot['workflow_execution_snapshot'] ?? null,
            );
            $contentNodeId = trim((string) ($wfSnap?->nodeIdForRole(
                WorkflowExecutionRole::ArticleContentGenerate->value,
            ) ?? ''));
        }
        if (
            $context->mode === ArticleWritingExecutionMode::ContentNode
            && $contentNodeId === ''
        ) {
            return $this->fail(
                $writing,
                [
                    'type' => $ownerType,
                    'prompt_id' => $promptId,
                    'owner_id' => $ownerId,
                    'prompt' => null,
                ],
                'Không thể thử lại lần chạy cũ. Hãy chọn «Chạy lại bằng cấu hình hiện tại».',
            );
        }

        $owner = [
            'type' => $ownerType,
            'prompt_id' => $promptId,
            'owner_id' => $ownerId,
            'prompt' => $promptId !== null && $promptId > 0
                ? SeoPrompt::query()->find($promptId)
                : null,
        ];

        $retryContext = new ArticleWritingExecutionContext(
            mode: $context->mode,
            promptOwnerType: $ownerType,
            siteId: $context->siteId,
            promptId: $promptId,
            promptOwnerId: $ownerId,
            workflowTask: $context->workflowTask,
            contentNodeId: $contentNodeId !== '' ? $contentNodeId : null,
            taskContext: $this->stampTaskContext($context->taskContext, $variables, $writing),
            useRetrySnapshot: false,
            expectedUpdatedAt: $context->expectedUpdatedAt,
            executionToken: $context->executionToken,
            baseVariables: $variables,
            persistArticle: $context->persistArticle,
        );

        return match ($context->mode) {
            ArticleWritingExecutionMode::PublishGraph => $this->executePublishGraph(
                $writing,
                $retryContext,
                $retryContext->taskContext,
                $owner,
            ),
            ArticleWritingExecutionMode::ContentNode => $this->executeContentNode(
                $writing,
                $retryContext,
                $retryContext->taskContext,
                $owner,
            ),
            ArticleWritingExecutionMode::DirectGenerate => $this->executeDirectGenerate(
                $writing,
                $retryContext,
                $retryContext->taskContext,
                $owner,
                $variables,
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function retrySnapshotIsComplete(
        array $snapshot,
        ArticleWritingExecutionContext $context,
    ): bool {
        $source = trim((string) ($snapshot['article_writing_source_type'] ?? $snapshot['source_type'] ?? ''));
        $ownerType = trim((string) ($snapshot['prompt_owner_type'] ?? ''));
        if ($source === '' || $ownerType === '') {
            return false;
        }

        if ($context->mode === ArticleWritingExecutionMode::ContentNode) {
            $nodeId = trim((string) ($snapshot['content_node_id'] ?? ''));
            if ($nodeId === '') {
                $wfSnap = WorkflowExecutionSnapshot::tryFromArray(
                    $snapshot['workflow_execution_snapshot'] ?? null,
                );
                $nodeId = trim((string) ($wfSnap?->nodeIdForRole(
                    WorkflowExecutionRole::ArticleContentGenerate->value,
                ) ?? ''));
            }
            if ($nodeId === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{type: ArticleWritingPromptOwnerType, prompt_id: ?int, owner_id: ?string, prompt: ?SeoPrompt}  $owner
     */
    private function executePublishGraph(
        ArticleWritingInput $writing,
        ArticleWritingExecutionContext $context,
        ?TaskTestContext $taskContext,
        array $owner,
    ): ArticleWritingExecutionResult {
        $task = $this->resolvePublishTask($context->workflowTask);
        if (! $taskContext instanceof TaskTestContext) {
            return $this->fail($writing, $owner, 'Thiếu TaskTestContext cho Publish graph.');
        }

        $steps = $this->workflowRunner->run($task, $taskContext);

        return $this->finalizeWorkflowSteps($writing, $owner, $taskContext, $steps, $context);
    }

    /**
     * @param  array{type: ArticleWritingPromptOwnerType, prompt_id: ?int, owner_id: ?string, prompt: ?SeoPrompt}  $owner
     */
    private function executeContentNode(
        ArticleWritingInput $writing,
        ArticleWritingExecutionContext $context,
        ?TaskTestContext $taskContext,
        array $owner,
    ): ArticleWritingExecutionResult {
        $task = $this->resolvePublishTask($context->workflowTask);
        if (! $taskContext instanceof TaskTestContext) {
            return $this->fail($writing, $owner, 'Thiếu TaskTestContext cho content node.');
        }

        $nodeId = trim((string) ($context->contentNodeId ?? ''));
        if ($nodeId === '') {
            $nodeId = $this->roleResolver->requireNodeId(
                $task,
                WorkflowExecutionRole::ArticleContentGenerate,
            );
        }
        if ($nodeId === '') {
            return $this->fail($writing, $owner, 'Không tìm thấy content node trong Publish workflow.');
        }

        // Không chạy outline lại — seed từ writing input / context vars / meta / PromptResult.
        if ($writing->input !== '') {
            $vars = is_array($taskContext->variables) ? $taskContext->variables : [];
            if (trim((string) ($vars['article_writing_raw_input'] ?? '')) === '') {
                $vars['article_writing_raw_input'] = $writing->input;
            }
            if (trim((string) ($vars['input'] ?? '')) === '') {
                $vars['input'] = $writing->input;
            }
            $taskContext = $taskContext->withVariables($vars);
        }

        $steps = $this->workflowRunner->runFromNodeId(
            $task,
            $taskContext,
            $nodeId,
            seedOutlineFromArticle: true,
        );

        return $this->finalizeWorkflowSteps($writing, $owner, $taskContext, $steps, $context);
    }

    /**
     * @param  array{type: ArticleWritingPromptOwnerType, prompt_id: ?int, owner_id: ?string, prompt: ?SeoPrompt}  $owner
     * @param  array<string, mixed>  $variables
     */
    private function executeDirectGenerate(
        ArticleWritingInput $writing,
        ArticleWritingExecutionContext $context,
        ?TaskTestContext $taskContext,
        array $owner,
        array $variables,
    ): ArticleWritingExecutionResult {
        $article = $taskContext?->article;
        if (! $article instanceof SeoArticle) {
            return $this->fail($writing, $owner, 'Editor full rewrite cần SeoArticle hiện có.');
        }

        if ($owner['type'] !== ArticleWritingPromptOwnerType::SettingsBinding) {
            return $this->fail(
                $writing,
                $owner,
                'Direct generate chỉ dùng Settings-owned Prompt (article.content.generate).',
            );
        }

        $prompt = $owner['prompt'] instanceof SeoPrompt
            ? $owner['prompt']
            : $this->promptBindingResolver->resolveSettingsHook(self::HOOK_KEY);

        try {
            $payload = $this->hookBinding->execute(
                $prompt,
                [
                    'input' => (string) ($variables['input'] ?? ''),
                    'post_title' => $writing->title,
                    'title' => $writing->title,
                    'focus_keyword' => $writing->keyword,
                    'keyword' => $writing->keyword,
                    'article_length' => $variables['article_length'] ?? null,
                    'source_type' => $writing->sourceType->value,
                    'article_writing_source_type' => $writing->sourceType->value,
                ],
                [
                    'article_id' => (int) $article->getKey(),
                    'site_id' => (int) ($article->site_id ?? $context->siteId ?? 0) ?: null,
                    'stage' => 'writing',
                    'source' => 'editor_direct_generate',
                    'locale' => $variables['language'] ?? $variables['locale'] ?? null,
                ],
                [],
            );
        } catch (\Throwable $exception) {
            return $this->fail($writing, $owner, $exception->getMessage());
        }

        $markdown = trim((string) ($payload['value'] ?? $payload['raw'] ?? $payload['output'] ?? ''));
        $history = $this->historyMetadata($writing, $owner, $variables);
        $lengthValidation = is_array($payload['length_validation'] ?? null)
            ? $payload['length_validation']
            : null;
        if ($lengthValidation !== null) {
            $history = array_merge($history, $lengthValidation);
        }
        foreach ([
            'prompt_result_id',
            'warning_code',
            'warning_message',
            'execution_source',
            'system_ai_execution_id',
            'system_ai_capability',
        ] as $metaKey) {
            if (array_key_exists($metaKey, $payload) && $payload[$metaKey] !== null && $payload[$metaKey] !== '') {
                $history[$metaKey] = $payload[$metaKey];
            }
        }

        if (! $context->persistArticle) {
            return new ArticleWritingExecutionResult(
                success: $markdown !== '',
                message: $markdown !== '' ? 'Đã sinh nội dung (không persist).' : 'Output trống.',
                sourceType: $writing->sourceType,
                promptOwnerType: $owner['type'],
                hookKey: self::HOOK_KEY,
                articleId: (int) $article->getKey(),
                promptId: $owner['prompt_id'] ?? (int) $prompt->getKey(),
                promptOwnerId: $owner['owner_id'],
                persistStatus: ArticleWritingExecutionResult::PERSIST_SKIPPED,
                historyMetadata: $history,
                writing: $writing,
            );
        }

        if (! $this->passesStaleGuard($article, $context)) {
            return new ArticleWritingExecutionResult(
                success: true,
                message: 'Kết quả bị bỏ qua vì bài đã được sửa sau khi job bắt đầu (ignored_stale).',
                sourceType: $writing->sourceType,
                promptOwnerType: $owner['type'],
                hookKey: self::HOOK_KEY,
                articleId: (int) $article->getKey(),
                promptId: $owner['prompt_id'] ?? (int) $prompt->getKey(),
                promptOwnerId: $owner['owner_id'],
                persistStatus: ArticleWritingExecutionResult::PERSIST_IGNORED_STALE,
                historyMetadata: array_merge($history, ['persist_status' => 'ignored_stale']),
                writing: $writing,
            );
        }

        $article->refresh();
        $publish = $this->publisher->publishArticle($article, $markdown, $variables);
        $ancillaryMeta = array_filter([
            'ancillary_status' => $publish['ancillary_status'] ?? null,
            'ancillary_failures' => $publish['ancillary_failures'] ?? null,
            'expected_content_hash' => $publish['expected_content_hash'] ?? null,
            'persisted_content_hash' => $publish['persisted_content_hash'] ?? null,
        ], static fn (mixed $v): bool => $v !== null && $v !== []);

        if ((bool) ($publish['conflict'] ?? false)) {
            return new ArticleWritingExecutionResult(
                success: true,
                message: 'Kết quả bị bỏ qua vì bài đã được sửa (ignored_stale).',
                sourceType: $writing->sourceType,
                promptOwnerType: $owner['type'],
                hookKey: self::HOOK_KEY,
                articleId: (int) $article->getKey(),
                promptId: $owner['prompt_id'] ?? (int) $prompt->getKey(),
                promptOwnerId: $owner['owner_id'],
                persistStatus: ArticleWritingExecutionResult::PERSIST_IGNORED_STALE,
                historyMetadata: array_merge($history, $ancillaryMeta, [
                    'persist_status' => 'ignored_stale',
                ]),
                writing: $writing,
            );
        }

        $ok = (bool) ($publish['success'] ?? false);
        $persistHistory = array_merge($history, $ancillaryMeta, [
            'persist_status' => $ok ? 'applied' : 'failed',
        ]);

        return new ArticleWritingExecutionResult(
            success: $ok,
            message: $ok
                ? $this->successMessage($persistHistory, (string) ($publish['message'] ?? 'Đã cập nhật bài.'))
                : (string) ($publish['message'] ?? 'Persist thất bại.'),
            sourceType: $writing->sourceType,
            promptOwnerType: $owner['type'],
            hookKey: self::HOOK_KEY,
            articleId: (int) $article->getKey(),
            promptId: $owner['prompt_id'] ?? (int) $prompt->getKey(),
            promptOwnerId: $owner['owner_id'],
            persistStatus: $ok
                ? ArticleWritingExecutionResult::PERSIST_APPLIED
                : ArticleWritingExecutionResult::PERSIST_FAILED,
            historyMetadata: $persistHistory,
            writing: $writing,
        );
    }

    /**
     * @param  array{type: ArticleWritingPromptOwnerType, prompt_id: ?int, owner_id: ?string, prompt: ?SeoPrompt}  $owner
     * @param  list<array<string, mixed>>  $steps
     */
    private function finalizeWorkflowSteps(
        ArticleWritingInput $writing,
        array $owner,
        TaskTestContext $taskContext,
        array $steps,
        ArticleWritingExecutionContext $context,
    ): ArticleWritingExecutionResult {
        $failed = collect($steps)->contains(
            static fn (array $step): bool => in_array((string) ($step['status'] ?? ''), ['failed', 'blocked'], true),
        );
        $articleId = $taskContext->article instanceof SeoArticle
            ? (int) $taskContext->article->getKey()
            : null;
        foreach (array_reverse($steps) as $step) {
            $id = (int) ($step['article_id'] ?? 0);
            if ($id > 0) {
                $articleId = $id;
                break;
            }
        }

        $history = $this->historyMetadata(
            $writing,
            $owner,
            is_array($taskContext->variables) ? $taskContext->variables : [],
        );
        $history = array_merge($history, $this->lengthValidationFromSteps($steps));

        if ($failed) {
            $message = 'Quy trình có bước lỗi.';
            foreach ($steps as $step) {
                if (($step['status'] ?? '') === 'failed') {
                    $message = trim((string) ($step['message'] ?? $message));
                    break;
                }
            }

            return new ArticleWritingExecutionResult(
                success: false,
                message: $message,
                sourceType: $writing->sourceType,
                promptOwnerType: $owner['type'],
                hookKey: self::HOOK_KEY,
                articleId: $articleId,
                promptId: $owner['prompt_id'],
                promptOwnerId: $owner['owner_id'],
                persistStatus: ArticleWritingExecutionResult::PERSIST_FAILED,
                steps: $steps,
                historyMetadata: $history,
                writing: $writing,
            );
        }

        // ContentNode / PublishGraph: workflow Save may already have written body before
        // finalize. An early ignored_stale here skips ensureGeneratedContentPersisted and
        // falsely reports human-edit conflict upstream (persist_status never becomes applied).
        $deferStaleToPersistGate = in_array($context->mode, [
            ArticleWritingExecutionMode::PublishGraph,
            ArticleWritingExecutionMode::ContentNode,
        ], true);

        if (! $deferStaleToPersistGate
            && $articleId !== null
            && $context->expectedUpdatedAt !== null
            && $taskContext->article instanceof SeoArticle
            && ! $this->passesStaleGuard($taskContext->article, $context)
        ) {
            return new ArticleWritingExecutionResult(
                success: true,
                message: 'Kết quả bị bỏ qua vì bài đã được sửa (ignored_stale).',
                sourceType: $writing->sourceType,
                promptOwnerType: $owner['type'],
                hookKey: self::HOOK_KEY,
                articleId: $articleId,
                promptId: $owner['prompt_id'],
                promptOwnerId: $owner['owner_id'],
                persistStatus: ArticleWritingExecutionResult::PERSIST_IGNORED_STALE,
                steps: $steps,
                historyMetadata: array_merge($history, ['persist_status' => 'ignored_stale']),
                writing: $writing,
            );
        }

        // PublishGraph / ContentNode: Outline-only is not success — need Content evidence.
        if ($deferStaleToPersistGate) {
            $article = $taskContext->article;
            if ($articleId !== null && $articleId > 0) {
                $fresh = SeoArticle::query()->find($articleId);
                if ($fresh instanceof SeoArticle) {
                    $article = $fresh;
                }
            }
            $evidence = \Omnichannel\Addons\AiPrompt\Support\WorkflowPublishContentEvidence::evaluate(
                $steps,
                $article instanceof SeoArticle ? $article : null,
                requireContent: true,
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

                return new ArticleWritingExecutionResult(
                    success: false,
                    message: $evidence['message'],
                    sourceType: $writing->sourceType,
                    promptOwnerType: $owner['type'],
                    hookKey: self::HOOK_KEY,
                    articleId: $articleId,
                    promptId: $owner['prompt_id'],
                    promptOwnerId: $owner['owner_id'],
                    persistStatus: ArticleWritingExecutionResult::PERSIST_FAILED,
                    steps: $steps,
                    historyMetadata: array_merge($history, [
                        'error_code' => $evidence['code'],
                    ]),
                    writing: $writing,
                );
            }

            // AI content step completed ≠ body written. Old semantic body must not
            // count as persist success when canonical hash differs from articles.body.
            $persistGate = $this->ensureGeneratedContentPersisted(
                $steps,
                $article instanceof SeoArticle ? $article : null,
                is_array($taskContext->variables) ? $taskContext->variables : [],
            );
            if ($persistGate['status'] === ArticleWritingExecutionResult::PERSIST_IGNORED_STALE) {
                return new ArticleWritingExecutionResult(
                    success: true,
                    message: (string) ($persistGate['message'] !== ''
                        ? $persistGate['message']
                        : 'Kết quả bị bỏ qua vì bài đã được sửa (ignored_stale).'),
                    sourceType: $writing->sourceType,
                    promptOwnerType: $owner['type'],
                    hookKey: self::HOOK_KEY,
                    articleId: $articleId,
                    promptId: $owner['prompt_id'],
                    promptOwnerId: $owner['owner_id'],
                    persistStatus: ArticleWritingExecutionResult::PERSIST_IGNORED_STALE,
                    steps: $steps,
                    historyMetadata: array_merge($history, [
                        'persist_status' => 'ignored_stale',
                        'expected_content_hash' => $persistGate['expected_content_hash'] ?? null,
                        'persisted_content_hash' => $persistGate['persisted_content_hash'] ?? null,
                        'ancillary_status' => $persistGate['ancillary_status'] ?? 'skipped',
                    ]),
                    writing: $writing,
                );
            }
            if ($persistGate['status'] === ArticleWritingExecutionResult::PERSIST_FAILED) {
                $steps[] = [
                    'node_id' => 'content-persist-guard',
                    'type' => 'guard',
                    'title' => 'Content persist',
                    'status' => 'failed',
                    'message' => $persistGate['message'],
                    'skip_reason' => 'content_body_not_applied',
                ];

                return new ArticleWritingExecutionResult(
                    success: false,
                    message: $persistGate['message'],
                    sourceType: $writing->sourceType,
                    promptOwnerType: $owner['type'],
                    hookKey: self::HOOK_KEY,
                    articleId: $articleId,
                    promptId: $owner['prompt_id'],
                    promptOwnerId: $owner['owner_id'],
                    persistStatus: ArticleWritingExecutionResult::PERSIST_FAILED,
                    steps: $steps,
                    historyMetadata: array_merge($history, [
                        'persist_status' => 'failed',
                        'expected_content_hash' => $persistGate['expected_content_hash'] ?? null,
                        'persisted_content_hash' => $persistGate['persisted_content_hash'] ?? null,
                        'ancillary_status' => $persistGate['ancillary_status'] ?? 'skipped',
                    ]),
                    writing: $writing,
                );
            }

            if ($persistGate['status'] === ArticleWritingExecutionResult::PERSIST_APPLIED
                && $article instanceof SeoArticle
            ) {
                $fresh = SeoArticle::query()->find((int) $article->getKey());
                if ($fresh instanceof SeoArticle) {
                    $taskContext = $taskContext->withArticle($fresh);
                    $articleId = (int) $fresh->getKey();
                }
                $history = array_merge($history, [
                    'persist_status' => 'applied',
                    'expected_content_hash' => $persistGate['expected_content_hash'] ?? null,
                    'persisted_content_hash' => $persistGate['persisted_content_hash'] ?? null,
                    'ancillary_status' => $persistGate['ancillary_status'] ?? 'applied',
                    'ancillary_failures' => $persistGate['ancillary_failures'] ?? [],
                ]);
            }
        }

        return new ArticleWritingExecutionResult(
            success: true,
            message: $this->successMessage($history, 'Đã chạy article writing.'),
            sourceType: $writing->sourceType,
            promptOwnerType: $owner['type'],
            hookKey: self::HOOK_KEY,
            articleId: $articleId,
            promptId: $owner['prompt_id'],
            promptOwnerId: $owner['owner_id'],
            persistStatus: ArticleWritingExecutionResult::PERSIST_APPLIED,
            steps: $steps,
            historyMetadata: $history,
            writing: $writing,
        );
    }

    /**
     * When a content.generate step completed with output, articles.body must reflect it.
     * If save_article / flush missed, attempt one late publish via PromptTestPublishService.
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $variables
     * @return array{
     *     status: string,
     *     message: string,
     *     expected_content_hash?: string,
     *     persisted_content_hash?: string
     * }
     */
    private function ensureGeneratedContentPersisted(
        array $steps,
        ?SeoArticle $article,
        array $variables,
    ): array {
        $expectation = $this->resolveContentPersistExpectation($steps);
        if ($expectation['kind'] === 'no_content_step') {
            return ['status' => ArticleWritingExecutionResult::PERSIST_APPLIED, 'message' => ''];
        }

        if ($expectation['kind'] === 'content_step_missing_output') {
            return [
                'status' => ArticleWritingExecutionResult::PERSIST_FAILED,
                'message' => 'Content writing step expected but generated output could not be resolved.',
            ];
        }

        $generated = (string) ($expectation['output'] ?? '');
        if ($article === null) {
            return [
                'status' => ArticleWritingExecutionResult::PERSIST_FAILED,
                'message' => 'Content writing step completed but article could not be resolved for persist.',
            ];
        }

        try {
            $prepared = $this->publisher->prepareArticleContent($article, $generated);
        } catch (\Throwable $e) {
            return [
                'status' => ArticleWritingExecutionResult::PERSIST_FAILED,
                'message' => 'Unable to prepare canonical writing HTML: '.$e->getMessage(),
            ];
        }

        $intendedHash = (string) $prepared['content_hash'];
        $body = (string) ($article->body ?? '');
        $persistedHash = $this->publisher->contentHash($body);
        $articleId = (int) $article->getKey();

        if ($intendedHash === $persistedHash) {
            \Illuminate\Support\Facades\Log::info('writing.trace.persist', [
                'article_id' => $articleId,
                'persist_status' => ArticleWritingExecutionResult::PERSIST_APPLIED,
                'expected_content_hash' => $intendedHash,
                'persisted_content_hash' => $persistedHash,
                'body_length' => strlen($body),
                'mode' => 'already_applied',
            ]);

            return [
                'status' => ArticleWritingExecutionResult::PERSIST_APPLIED,
                'message' => '',
                'expected_content_hash' => $intendedHash,
                'persisted_content_hash' => $persistedHash,
            ];
        }

        try {
            $publish = $this->publisher->publishArticle($article, $generated, $variables);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('writing.trace.persist', [
                'article_id' => $articleId,
                'persist_status' => ArticleWritingExecutionResult::PERSIST_FAILED,
                'expected_content_hash' => $intendedHash,
                'mode' => 'late_publish_exception',
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => ArticleWritingExecutionResult::PERSIST_FAILED,
                'message' => 'Writing generation succeeded but body persist failed: '.$e->getMessage(),
                'expected_content_hash' => $intendedHash,
                'persisted_content_hash' => $persistedHash,
            ];
        }

        if ((bool) ($publish['conflict'] ?? false)) {
            \Illuminate\Support\Facades\Log::warning('writing.trace.persist', [
                'article_id' => $articleId,
                'persist_status' => ArticleWritingExecutionResult::PERSIST_IGNORED_STALE,
                'expected_content_hash' => $intendedHash,
                'persisted_content_hash' => $publish['persisted_content_hash'] ?? $persistedHash,
                'mode' => 'late_publish_conflict',
                'message' => (string) ($publish['message'] ?? ''),
            ]);

            return [
                'status' => ArticleWritingExecutionResult::PERSIST_IGNORED_STALE,
                'message' => 'Kết quả bị bỏ qua vì bài đã được sửa (ignored_stale).',
                'expected_content_hash' => $intendedHash,
                'persisted_content_hash' => (string) ($publish['persisted_content_hash'] ?? $persistedHash),
                'ancillary_status' => (string) ($publish['ancillary_status'] ?? 'skipped'),
            ];
        }

        $expectedAfter = (string) ($publish['expected_content_hash'] ?? $intendedHash);
        $persistedAfter = (string) ($publish['persisted_content_hash'] ?? '');

        if (! ($publish['success'] ?? false) || $expectedAfter === '' || $expectedAfter !== $persistedAfter) {
            \Illuminate\Support\Facades\Log::warning('writing.trace.persist', [
                'article_id' => $articleId,
                'persist_status' => ArticleWritingExecutionResult::PERSIST_FAILED,
                'expected_content_hash' => $expectedAfter !== '' ? $expectedAfter : $intendedHash,
                'persisted_content_hash' => $persistedAfter !== '' ? $persistedAfter : $persistedHash,
                'mode' => 'late_publish_rejected',
                'message' => (string) ($publish['message'] ?? ''),
            ]);

            return [
                'status' => ArticleWritingExecutionResult::PERSIST_FAILED,
                'message' => 'Writing generation succeeded but body was not applied: '
                    .trim((string) ($publish['message'] ?? 'publishArticle failed')),
                'expected_content_hash' => $expectedAfter !== '' ? $expectedAfter : $intendedHash,
                'persisted_content_hash' => $persistedAfter !== '' ? $persistedAfter : $persistedHash,
                'ancillary_status' => (string) ($publish['ancillary_status'] ?? 'skipped'),
            ];
        }

        \Illuminate\Support\Facades\Log::info('writing.trace.persist', [
            'article_id' => $articleId,
            'persist_status' => ArticleWritingExecutionResult::PERSIST_APPLIED,
            'expected_content_hash' => $expectedAfter,
            'persisted_content_hash' => $persistedAfter,
            'body_length' => (int) ($publish['body_length'] ?? 0),
            'mode' => 'late_persisted',
            'ancillary_status' => $publish['ancillary_status'] ?? null,
        ]);

        return [
            'status' => ArticleWritingExecutionResult::PERSIST_APPLIED,
            'message' => 'Late-persisted AI writing output to articles.body.',
            'expected_content_hash' => $expectedAfter,
            'persisted_content_hash' => $persistedAfter,
            'ancillary_status' => (string) ($publish['ancillary_status'] ?? 'applied'),
            'ancillary_failures' => is_array($publish['ancillary_failures'] ?? null)
                ? $publish['ancillary_failures']
                : [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array{kind: 'no_content_step'|'content_step_missing_output'|'has_output', output?: string}
     */
    private function resolveContentPersistExpectation(array $steps): array
    {
        if (! $this->hasContentWritingStep($steps)) {
            return ['kind' => 'no_content_step'];
        }

        $output = $this->latestCompletedContentOutput($steps);
        if ($output === null) {
            return ['kind' => 'content_step_missing_output'];
        }

        return ['kind' => 'has_output', 'output' => $output];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function hasContentWritingStep(array $steps): bool
    {
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            if ($this->isContentWritingStep($step)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function isContentWritingStep(array $step): bool
    {
        $hook = strtolower(trim((string) ($step['hook_key'] ?? '')));
        if (str_contains($hook, 'content.generate')
            || str_contains($hook, 'content.rewrite')
            || str_contains($hook, 'content.improve')
        ) {
            return true;
        }

        return ($step['artifact_type'] ?? '') === 'article_content';
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function latestCompletedContentOutput(array $steps): ?string
    {
        foreach (array_reverse($steps) as $step) {
            if (! is_array($step)) {
                continue;
            }
            $status = strtolower(trim((string) ($step['status'] ?? '')));
            if (! in_array($status, ['completed', 'success', 'succeeded'], true)) {
                continue;
            }
            if (! $this->isContentWritingStep($step)) {
                continue;
            }
            $output = trim((string) ($step['output'] ?? ''));
            if ($output === '' && isset($step['outputs']['total'])) {
                $output = trim((string) $step['outputs']['total']);
            }
            if ($output !== '') {
                return $output;
            }
        }

        return null;
    }

    private function resolveSource(
        ArticleWritingSourceType $sourceType,
        array $variables,
        ?SeoArticle $article,
        ArticleWritingInput $seed,
    ): ArticleWritingInput {
        // Ưu tiên seed đã đủ (caller đã resolve) khi input không rỗng / brief có field.
        if ($seed->sourceType === $sourceType && $this->seedUsable($seed)) {
            return $seed;
        }

        return match ($sourceType) {
            ArticleWritingSourceType::Outline => $this->outlineProvider->resolve($variables, $article),
            ArticleWritingSourceType::ExistingArticle => $this->existingProvider->resolve($variables, $article),
            ArticleWritingSourceType::Brief => $this->briefProvider->resolve($variables, $article),
        };
    }

    private function seedUsable(ArticleWritingInput $seed): bool
    {
        return match ($seed->sourceType) {
            ArticleWritingSourceType::Brief => trim($seed->title.$seed->keyword.$seed->description.$seed->input) !== '',
            default => trim($seed->input) !== '',
        };
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{type: ArticleWritingPromptOwnerType, prompt_id: ?int, owner_id: ?string, prompt: ?SeoPrompt}
     */
    private function resolvePromptOwner(ArticleWritingExecutionContext $context, array $variables): array
    {
        if ($context->promptOwnerType === ArticleWritingPromptOwnerType::WorkflowNode) {
            $promptId = $context->promptId;
            if ($promptId === null || $promptId <= 0) {
                $promptId = isset($variables['prompt_id']) ? (int) $variables['prompt_id'] : null;
            }
            if (($promptId === null || $promptId <= 0) && $context->workflowTask instanceof SeoTask) {
                $promptId = $this->resolveContentPromptId($context->workflowTask, $context->contentNodeId);
            }
            // PublishGraph: node content chạy sau — prompt_id có thể null ở entry.
            if (($promptId === null || $promptId <= 0)
                && $context->mode !== ArticleWritingExecutionMode::PublishGraph
            ) {
                throw new \InvalidArgumentException('Workflow node thiếu prompt_id.');
            }

            return [
                'type' => ArticleWritingPromptOwnerType::WorkflowNode,
                'prompt_id' => $promptId,
                'owner_id' => $context->promptOwnerId ?? $context->contentNodeId,
                'prompt' => $promptId !== null && $promptId > 0
                    ? SeoPrompt::query()->find($promptId)
                    : null,
            ];
        }

        // Settings only — không resolve workflow prompt_id song song.
        $prompt = $this->promptBindingResolver->resolveSettingsHook(self::HOOK_KEY);
        $promptId = (int) $prompt->getKey();

        return [
            'type' => ArticleWritingPromptOwnerType::SettingsBinding,
            'prompt_id' => $promptId,
            'owner_id' => $context->promptOwnerId ?? self::HOOK_KEY,
            'prompt' => $prompt,
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function applyArticleLength(
        array $variables,
        ArticleWritingExecutionContext $context,
        bool $retry,
    ): array {
        if ($retry && isset($context->retrySnapshot['article_length'])) {
            $variables['article_length'] = $context->retrySnapshot['article_length'];

            return $variables;
        }

        if (! isset($variables['article_length']) || $variables['article_length'] === '' || $variables['article_length'] === null) {
            $postType = trim((string) ($variables['post_type'] ?? 'article'));
            $variables['article_length'] = $this->promptSettings->resolveArticleLengthTarget(
                $postType !== '' ? $postType : 'article',
            );
        }

        return $variables;
    }

    private function resolvePublishTask(?SeoTask $workflowTask): SeoTask
    {
        if ($workflowTask instanceof SeoTask) {
            return $workflowTask;
        }

        $taskId = $this->settings->getPublishArticleTaskId();
        if ($taskId === null) {
            throw new \InvalidArgumentException(
                'Chưa cấu hình quy trình Đăng bài viết. Vào SEO → Cài đặt → Quy trình để chọn task.',
            );
        }

        $task = SeoTask::query()->find($taskId);
        if (! $task instanceof SeoTask) {
            throw new \InvalidArgumentException('Quy trình tạo bài viết (#'.$taskId.') không tồn tại.');
        }

        if (! $task->is_active) {
            throw new \InvalidArgumentException('Quy trình «'.$task->name.'» đang tắt.');
        }

        return $task;
    }

    private function resolveContentPromptId(SeoTask $task, ?string $contentNodeId): ?int
    {
        $nodeId = trim((string) $contentNodeId);
        if ($nodeId === '') {
            $found = $this->roleResolver->findNode($task, WorkflowExecutionRole::ArticleContentGenerate);
            $nodeId = $found['node_id'] ?? '';
            if ($found !== null && ($found['prompt_id'] ?? null) !== null) {
                return (int) $found['prompt_id'];
            }
        }
        if ($nodeId === '') {
            return null;
        }

        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        foreach ($nodes as $node) {
            if (! is_array($node) || trim((string) ($node['id'] ?? '')) !== $nodeId) {
                continue;
            }
            $promptId = isset($node['data']['promptId']) ? (int) $node['data']['promptId'] : 0;

            return $promptId > 0 ? $promptId : null;
        }

        return null;
    }

    private function stampTaskContext(
        ?TaskTestContext $taskContext,
        array $variables,
        ArticleWritingInput $writing,
    ): ?TaskTestContext {
        if (! $taskContext instanceof TaskTestContext) {
            return null;
        }

        return $taskContext->withVariables(array_merge($variables, [
            'article_writing_source_type' => $writing->sourceType->value,
            'source_type' => $writing->sourceType->value,
        ]));
    }

    private function passesStaleGuard(SeoArticle $article, ArticleWritingExecutionContext $context): bool
    {
        $expected = trim((string) ($context->expectedUpdatedAt ?? ''));
        if ($expected === '') {
            return true;
        }

        $article->refresh();
        $current = $article->updated_at?->toIso8601String() ?? '';

        return $current === '' || $current === $expected;
    }

    private function assertValidSourceType(ArticleWritingSourceType $sourceType): void
    {
        // Enum đã hẹp — giữ chỗ reject nếu mở rộng sai.
        if (! in_array($sourceType, [
            ArticleWritingSourceType::Outline,
            ArticleWritingSourceType::ExistingArticle,
            ArticleWritingSourceType::Brief,
        ], true)) {
            throw new \InvalidArgumentException('source_type không hợp lệ cho article writing.');
        }
    }

    /**
     * @param  array{type: ArticleWritingPromptOwnerType, prompt_id: ?int, owner_id: ?string, prompt: ?SeoPrompt}  $owner
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    private function lengthValidationFromSteps(array $steps): array
    {
        foreach (array_reverse($steps) as $step) {
            if (! is_array($step)) {
                continue;
            }
            $fromStep = is_array($step['length_validation'] ?? null) ? $step['length_validation'] : $step;
            if (ArticleGenerationLengthValidator::isWarningResult($fromStep)
                || isset($fromStep['length_validation_result'])
            ) {
                return array_filter([
                    'length_validation' => is_array($step['length_validation'] ?? null) ? $step['length_validation'] : null,
                    'actual_word_count' => $fromStep['actual_word_count'] ?? null,
                    'actual_words' => $fromStep['actual_words'] ?? $fromStep['actual_word_count'] ?? null,
                    'minimum_acceptable_words' => $fromStep['minimum_acceptable_words'] ?? null,
                    'hard_floor_words' => $fromStep['hard_floor_words'] ?? $fromStep['minimum_acceptable_words'] ?? null,
                    'target_article_length' => $fromStep['target_article_length'] ?? null,
                    'target_words' => $fromStep['target_words'] ?? $fromStep['target_article_length'] ?? null,
                    'length_validation_result' => $fromStep['length_validation_result'] ?? null,
                    'outcome' => $fromStep['outcome'] ?? null,
                    'warning_code' => $fromStep['warning_code'] ?? null,
                    'warning_message' => $fromStep['warning_message'] ?? null,
                ], static fn (mixed $v): bool => $v !== null && $v !== '');
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $history
     */
    private function successMessage(array $history, string $fallback): string
    {
        if (ArticleGenerationLengthValidator::isWarningResult($history)) {
            $warning = trim((string) ($history['warning_message'] ?? ''));

            return $warning !== '' ? $warning : ArticleGenerationLengthValidator::UI_WARNING;
        }

        return $fallback;
    }

    private function historyMetadata(ArticleWritingInput $writing, array $owner, array $variables): array
    {
        $wfSnap = WorkflowExecutionSnapshot::tryFromArray(
            $variables['workflow_execution_snapshot'] ?? ($writing->metadata['workflow_execution_snapshot'] ?? null),
        );

        return array_merge($writing->historyMetadata(), [
            'source_type' => $writing->sourceType->value,
            'workflow_id' => $wfSnap?->workflowId
                ?? (isset($variables['workflow_id']) ? (int) $variables['workflow_id'] : null),
            'workflow_hash' => $wfSnap?->flowDataHash
                ?? (isset($variables['flow_data_hash']) ? (string) $variables['flow_data_hash'] : null),
            'node_id' => $variables['content_node_id']
                ?? ($writing->metadata['content_node_id'] ?? null),
            'execution_role' => $variables['execution_role']
                ?? ($writing->metadata['execution_role'] ?? null),
            'prompt_owner_type' => $owner['type']->value,
            'prompt_owner_id' => $owner['owner_id'],
            'prompt_id' => $owner['prompt_id'],
            'hook_key' => self::HOOK_KEY,
            'canonical_prompt_key' => self::HOOK_KEY,
            'stage' => self::HOOK_KEY,
            'source_hash' => $variables['article_source_artifact_hash']
                ?? ($variables['outline_artifact_hash'] ?? ($writing->metadata['article_source_artifact_hash'] ?? null)),
            'retry_or_rerun' => $variables['retry_or_rerun'] ?? 'rerun',
            'article_length' => $variables['article_length'] ?? ($writing->metadata['article_length'] ?? null),
            'legacy_adapter' => (bool) ($writing->metadata['legacy_rewrite_adapter'] ?? false),
            'legacy_caller' => $writing->metadata['legacy_caller'] ?? null,
            'project_item_id' => isset($variables['project_item_id']) && is_numeric($variables['project_item_id'])
                ? (int) $variables['project_item_id']
                : (isset($writing->metadata['project_item_id']) && is_numeric($writing->metadata['project_item_id'])
                    ? (int) $writing->metadata['project_item_id']
                    : null),
            'run_id' => isset($variables['run_id']) && is_numeric($variables['run_id'])
                ? (int) $variables['run_id']
                : (isset($writing->metadata['run_id']) && is_numeric($writing->metadata['run_id'])
                    ? (int) $writing->metadata['run_id']
                    : null),
            'correlation_id' => isset($variables['correlation_id'])
                ? (string) $variables['correlation_id']
                : (isset($writing->metadata['correlation_id']) ? (string) $writing->metadata['correlation_id'] : null),
            'retry_attempt' => isset($variables['attempt']) && is_numeric($variables['attempt'])
                ? (int) $variables['attempt']
                : null,
        ]);
    }

    /**
     * @param  array{type: ArticleWritingPromptOwnerType, prompt_id: ?int, owner_id: ?string, prompt: ?SeoPrompt}  $owner
     */
    private function fail(
        ArticleWritingInput $writing,
        array $owner,
        string $message,
    ): ArticleWritingExecutionResult {
        return new ArticleWritingExecutionResult(
            success: false,
            message: $message,
            sourceType: $writing->sourceType,
            promptOwnerType: $owner['type'],
            hookKey: self::HOOK_KEY,
            articleId: $writing->articleId,
            promptId: $owner['prompt_id'],
            promptOwnerId: $owner['owner_id'],
            persistStatus: ArticleWritingExecutionResult::PERSIST_FAILED,
            historyMetadata: $this->historyMetadata($writing, $owner, []),
            writing: $writing,
        );
    }
}
