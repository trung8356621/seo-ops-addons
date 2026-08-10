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
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookExecutionService;
use Omnichannel\Addons\Content\Services\ArticleWriting\BriefArticleWritingSourceProvider;
use Omnichannel\Addons\Content\Services\ArticleWriting\ExistingArticleWritingSourceProvider;
use Omnichannel\Addons\Content\Services\ArticleWriting\OutlineArticleWritingSourceProvider;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptBindingResolver;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionRoleResolver;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionContext;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionResult;
use Omnichannel\Addons\Content\Support\ArticleWritingInput;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use Omnichannel\Addons\ContentProjects\Support\WorkflowExecutionSnapshot;

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
        private readonly PromptHookExecutionService $hookExecution,
        private readonly PromptTestPublishService $publisher,
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

        return match ($context->mode) {
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
        };
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
            $hookResult = $this->hookExecution->execute(
                self::HOOK_KEY,
                (int) $article->getKey(),
                [
                    'input' => (string) ($variables['input'] ?? ''),
                    'post_title' => $writing->title,
                    'focus_keyword' => $writing->keyword,
                    'article_length' => $variables['article_length'] ?? null,
                    'source_type' => $writing->sourceType->value,
                    'article_writing_source_type' => $writing->sourceType->value,
                ],
                $prompt,
            );
        } catch (\Throwable $exception) {
            return $this->fail($writing, $owner, $exception->getMessage());
        }

        $markdown = trim((string) ($hookResult->output['value'] ?? $hookResult->output['raw'] ?? ''));
        $history = $this->historyMetadata($writing, $owner, $variables);
        $lengthValidation = is_array($hookResult->output['length_validation'] ?? null)
            ? $hookResult->output['length_validation']
            : null;
        if ($lengthValidation !== null) {
            $history = array_merge($history, $lengthValidation);
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
        $ok = (bool) ($publish['success'] ?? false);

        return new ArticleWritingExecutionResult(
            success: $ok,
            message: (string) ($publish['message'] ?? ($ok ? 'Đã cập nhật bài.' : 'Persist thất bại.')),
            sourceType: $writing->sourceType,
            promptOwnerType: $owner['type'],
            hookKey: self::HOOK_KEY,
            articleId: (int) $article->getKey(),
            promptId: $owner['prompt_id'] ?? (int) $prompt->getKey(),
            promptOwnerId: $owner['owner_id'],
            persistStatus: $ok
                ? ArticleWritingExecutionResult::PERSIST_APPLIED
                : ArticleWritingExecutionResult::PERSIST_FAILED,
            historyMetadata: $history,
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

        if ($articleId !== null
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

        return new ArticleWritingExecutionResult(
            success: true,
            message: 'Đã chạy article writing.',
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
            'source_hash' => $variables['article_source_artifact_hash']
                ?? ($variables['outline_artifact_hash'] ?? ($writing->metadata['article_source_artifact_hash'] ?? null)),
            'retry_or_rerun' => $variables['retry_or_rerun'] ?? 'rerun',
            'article_length' => $variables['article_length'] ?? ($writing->metadata['article_length'] ?? null),
            'legacy_adapter' => (bool) ($writing->metadata['legacy_rewrite_adapter'] ?? false),
            'legacy_caller' => $writing->metadata['legacy_caller'] ?? null,
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
