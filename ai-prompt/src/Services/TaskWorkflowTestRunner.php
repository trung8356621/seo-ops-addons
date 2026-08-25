<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowArtifactType;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Services\ArticleEditorMediaAiService;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use Omnichannel\Addons\Media\Services\SeoMediaStorageService;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBinding;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExplicitBindingExecutor;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookUiFailureMapper;
use Omnichannel\Addons\ContentProjects\Services\Workflow\ArtifactReusePolicy;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use Omnichannel\Addons\Content\Services\ArticleWritingAssembler;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\Content\Services\ArticleWritingLegacyRewriteAdapter;
use Omnichannel\Addons\Content\Services\ArticleContentFaqService;
use Omnichannel\Addons\Content\Services\ArticleProductGalleryDistributeService;
use Omnichannel\Addons\Content\Services\SeoFaqPersistenceService;
use Omnichannel\Addons\Content\Support\ArticleGenerationSourceResult;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Omnichannel\Addons\AiPrompt\Support\PromptMediaPersistContext;
use Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoRuleViolationsResolver;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Seo\Services\SeoScoringCalculator;
use Omnichannel\Addons\Seo\Services\WorkflowKeywordResearchService;
use Omnichannel\Addons\WordPress\Services\WordPressCommentReviewService;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskOriginVariables;
use Omnichannel\Addons\ContentProjects\Support\Workflow\WorkflowTypedArtifact;
use Omnichannel\Addons\ContentProjects\Support\WorkflowExecutionState;
use App\Models\Site;
use Illuminate\Support\Str;
use Omnichannel\Addons\Content\Services\ArticleMarkdownToHtmlService;
use Omnichannel\Addons\AiPrompt\Support\WorkflowGraphReachability;

final class TaskWorkflowTestRunner
{
    public function __construct(
        private readonly PromptRunnerService $promptRunner,
        private readonly WorkflowParserService $workflowParser,
        private readonly WorkflowKeywordResearchService $keywordResearch,
        private readonly SeoFaqPersistenceService $faqPersistence,
        private readonly WorkflowTagExtractorService $tagExtractor,
        private readonly WordPressCommentReviewService $commentReviewPublisher,
        private readonly PromptTestPublishService $promptPublisher,
        private readonly WorkflowExistingAiOutputService $existingAiOutput,
        private readonly SeoMediaStorageService $mediaStorage,
        private readonly SeoCreateArticleSettingsService $createArticleSettings,
        private readonly ArticleMediaLocalService $articleMediaLocal,
        private readonly PromptHookExplicitBindingExecutor $hookBindingExecutor,
        private readonly ArticleGenerationInputResolver $articleGenerationInput,
        private readonly ArticleWritingAssembler $articleWritingAssembler,
        private readonly ArticleWritingLegacyRewriteAdapter $legacyRewriteAdapter,
        private readonly ArtifactReusePolicy $artifactReusePolicy = new ArtifactReusePolicy,
        private readonly PromptHookUiFailureMapper $hookFailureMapper = new PromptHookUiFailureMapper,
        private readonly ArticleOutlineVocabularySplitExecutor $outlineSplitExecutor,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function run(SeoTask $task, TaskTestContext $context): array
    {
        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        $edges = $this->normalizeWorkflowEdges(
            is_array($flow['edges'] ?? null) ? $flow['edges'] : [],
            $nodes,
        );
        $ordered = $this->orderedNodesForTask($task);
        $state = $this->initialState($context);
        $steps = [];
        $outlineFailed = false;
        $contentFailed = false;

        foreach ($ordered as $node) {
            if ($outlineFailed && $this->shouldSkipAfterOutlineFailure($node)) {
                $steps[] = [
                    'node_id' => (string) ($node['id'] ?? ''),
                    'type' => (string) ($node['type'] ?? ''),
                    'title' => (string) ($node['title'] ?? 'Bước'),
                    'status' => 'skipped',
                    'message' => 'Không chạy vì bước Dàn ý thất bại.',
                    'skip_reason' => 'outline_failed',
                ];

                continue;
            }

            if ($contentFailed && $this->shouldBlockAfterContentFailure($node)) {
                $steps[] = [
                    'node_id' => (string) ($node['id'] ?? ''),
                    'type' => (string) ($node['type'] ?? ''),
                    'title' => (string) ($node['title'] ?? 'Bước'),
                    'status' => 'blocked',
                    'message' => 'Không ghi nội dung vì bước Viết bài chưa tạo được article_content artifact hợp lệ.',
                    'skip_reason' => 'content_artifact_missing',
                ];

                continue;
            }

            try {
                $step = $this->executeNode($node, $context, $state, $edges);
                $steps[] = $step;
                if (($step['status'] ?? '') === 'failed' && $this->isOutlineRoleNode($node, $step['hook_key'] ?? null)) {
                    $outlineFailed = true;
                }
                if (($step['status'] ?? '') === 'failed' && $this->isContentRoleNode($node)) {
                    $contentFailed = true;
                }
            } catch (\Throwable $exception) {
                // Một node lỗi không được làm mất toàn bộ các bước đã chạy → ghi nhận bước «failed».
                $steps[] = [
                    'node_id' => (string) ($node['id'] ?? ''),
                    'type' => (string) ($node['type'] ?? ''),
                    'title' => (string) ($node['title'] ?? 'Bước'),
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                ];
                if ($this->isOutlineRoleNode($node)) {
                    $outlineFailed = true;
                }
                if ($this->isContentRoleNode($node)) {
                    $contentFailed = true;
                }
            }
        }

        $this->flushPendingArticleContentIfNeeded($state, $context);

        return $steps;
    }

    /**
     * @param  list<array<string, mixed>>  $priorSteps
     * @return array<string, mixed>
     */
    public function runSingleStep(
        SeoTask $task,
        TaskTestContext $context,
        string $nodeId,
        array $priorSteps = [],
        ?string $modelOverride = null,
    ): array {
        $ordered = $this->orderedNodesForTask($task);
        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        $edges = $this->normalizeWorkflowEdges(
            is_array($flow['edges'] ?? null) ? $flow['edges'] : [],
            $nodes,
        );
        $state = $this->buildStateFromSteps($priorSteps, $context);

        foreach ($ordered as $node) {
            if ((string) ($node['id'] ?? '') === $nodeId) {
                if (filled($modelOverride)) {
                    $promptId = $node['data']['promptId'] ?? null;
                    $prompt = $this->resolvePrompt($promptId);
                    $isImagePipeline = $prompt !== null
                        && \Omnichannel\Addons\Media\Support\ImageToolType::fromMixed($prompt->tools ?? 'default')
                            ->isImagePipeline();

                    // Image node: bỏ override category/text model (vd. gemini-3-flash-preview).
                    if (! $isImagePipeline) {
                        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
                        $data['aiModel'] = $modelOverride;
                        $node['data'] = $data;
                    }
                }

                return $this->executeNode($node, $context, $state, $edges);
            }
        }

        throw new \InvalidArgumentException('Không tìm thấy bước quy trình: '.$nodeId);
    }

    /**
     * Descendants reachable from a start node (directed edges source → target).
     *
     * @param  list<array<string, mixed>>  $edges
     * @return list<string>
     */
    public function reachableNodeIdsFrom(string $startNodeId, array $edges): array
    {
        return WorkflowGraphReachability::reachableNodeIdsFrom($startNodeId, $edges);
    }

    /**
     * Chạy từ node này và mọi downstream nodes theo directed graph (không phải topo index slice).
     * Node trước start được skip; khi $seedOutlineFromArticle=true, hydrate outline từ article hiện có.
     *
     * @return list<array<string, mixed>>
     */
    public function runFromNodeId(
        SeoTask $task,
        TaskTestContext $context,
        string $startNodeId,
        bool $seedOutlineFromArticle = false,
    ): array {
        $startNodeId = trim($startNodeId);
        if ($startNodeId === '') {
            throw new \InvalidArgumentException('Thiếu start node id.');
        }

        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        $edges = $this->normalizeWorkflowEdges(
            is_array($flow['edges'] ?? null) ? $flow['edges'] : [],
            $nodes,
        );
        $ordered = $this->orderedNodes($nodes, $edges);
        $reachableIds = $this->reachableNodeIdsFrom($startNodeId, $edges);
        if ($reachableIds === [] || ! in_array($startNodeId, $reachableIds, true)) {
            throw new \InvalidArgumentException('Không tìm thấy bước bắt đầu: '.$startNodeId);
        }
        $reachableLookup = array_fill_keys($reachableIds, true);

        $state = $this->initialState($context);
        $outlineMarkdown = '';
        if ($seedOutlineFromArticle) {
            $outlineMarkdown = $this->seedOutlineStateForContentRerun($state, $context);
            if ($outlineMarkdown === '') {
                throw new \InvalidArgumentException(
                    'Không tìm thấy outline để tạo lại bài.',
                );
            }
        }

        $steps = [];
        $statusByNodeId = [];
        $outlineFailed = false;
        $contentFailed = false;

        foreach ($ordered as $node) {
            $nodeId = (string) ($node['id'] ?? '');
            if ($nodeId === '') {
                continue;
            }

            if (! isset($reachableLookup[$nodeId])) {
                if ($outlineMarkdown !== '') {
                    $this->hydrateSkippedNodeWithOutline($node, $state, $outlineMarkdown);
                }

                $steps[] = [
                    'node_id' => $nodeId,
                    'type' => (string) ($node['type'] ?? ''),
                    'title' => (string) ($node['title'] ?? 'Bước'),
                    'status' => 'skipped',
                    'message' => 'Bỏ qua — không thuộc downstream graph từ điểm bắt đầu.',
                    'skip_reason' => 'not_reachable',
                ];
                $statusByNodeId[$nodeId] = 'not_reachable';

                continue;
            }

            if (WorkflowGraphReachability::hasBlockedPredecessor($nodeId, $edges, $statusByNodeId, $reachableIds)) {
                $steps[] = [
                    'node_id' => $nodeId,
                    'type' => (string) ($node['type'] ?? ''),
                    'title' => (string) ($node['title'] ?? 'Bước'),
                    'status' => 'skipped',
                    'message' => 'Bỏ qua — bước upstream thất bại.',
                    'skip_reason' => 'upstream_failed',
                ];
                $statusByNodeId[$nodeId] = 'skipped_upstream';

                continue;
            }

            if ($outlineFailed && $this->shouldSkipAfterOutlineFailure($node)) {
                $steps[] = [
                    'node_id' => $nodeId,
                    'type' => (string) ($node['type'] ?? ''),
                    'title' => (string) ($node['title'] ?? 'Bước'),
                    'status' => 'skipped',
                    'message' => 'Không chạy vì bước Dàn ý thất bại.',
                    'skip_reason' => 'outline_failed',
                ];
                $statusByNodeId[$nodeId] = 'skipped_upstream';

                continue;
            }

            if ($contentFailed && $this->shouldBlockAfterContentFailure($node)) {
                $steps[] = [
                    'node_id' => $nodeId,
                    'type' => (string) ($node['type'] ?? ''),
                    'title' => (string) ($node['title'] ?? 'Bước'),
                    'status' => 'blocked',
                    'message' => 'Không ghi nội dung vì bước Viết bài chưa tạo được article_content artifact hợp lệ.',
                    'skip_reason' => 'content_artifact_missing',
                ];
                $statusByNodeId[$nodeId] = 'blocked';

                continue;
            }

            try {
                $step = $this->executeNode($node, $context, $state, $edges);
                $steps[] = $step;
                $status = (string) ($step['status'] ?? '');
                if (in_array($status, ['failed', 'blocked'], true)) {
                    $statusByNodeId[$nodeId] = $status;
                } elseif ($status === 'skipped') {
                    $statusByNodeId[$nodeId] = 'skipped_upstream';
                } else {
                    $statusByNodeId[$nodeId] = 'completed';
                }

                if ($status === 'failed' && $this->isOutlineRoleNode($node, $step['hook_key'] ?? null)) {
                    $outlineFailed = true;
                }
                if ($status === 'failed' && $this->isContentRoleNode($node)) {
                    $contentFailed = true;
                }
            } catch (\Throwable $exception) {
                $steps[] = [
                    'node_id' => $nodeId,
                    'type' => (string) ($node['type'] ?? ''),
                    'title' => (string) ($node['title'] ?? 'Bước'),
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                ];
                $statusByNodeId[$nodeId] = 'failed';
                if ($this->isOutlineRoleNode($node)) {
                    $outlineFailed = true;
                }
                if ($this->isContentRoleNode($node)) {
                    $contentFailed = true;
                }
            }
        }

        $this->flushPendingArticleContentIfNeeded($state, $context);

        return $steps;
    }

    /**
     * Seed outline for content-node rerun — never meta-only.
     * Priority: context artifact (just produced upstream) → article meta → generation resolver (run/PromptResult).
     *
     * @return string Outline markdown đã seed (có thể rỗng)
     */
    private function seedOutlineStateForContentRerun(WorkflowExecutionState $state, TaskTestContext $context): string
    {
        $candidates = [
            trim((string) ($context->variables['article_writing_raw_input'] ?? '')),
            trim((string) ($context->variables['input'] ?? '')),
            trim((string) ($context->variables['direct_publish_outline_markdown'] ?? '')),
            trim((string) ($state->meta['direct_publish_outline_markdown'] ?? '')),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && $this->articleGenerationInput->isValidArtifact($candidate)) {
                return $this->applySeededOutlineToState($state, $candidate);
            }
        }

        $article = $context->article ?? $state->article;
        if ($article instanceof SeoArticle) {
            $article->loadMissing('articleMetas');
            $fromMeta = trim((string) (
                $article->articleMetas->firstWhere('meta_key', 'seo_article_outline')?->meta_value ?? ''
            ));
            if ($fromMeta !== '') {
                // Meta may be marked artifact or usable plain outline.
                if ($this->articleGenerationInput->isValidArtifact($fromMeta)
                    || $this->isUsablePlainOutline($fromMeta)
                ) {
                    return $this->applySeededOutlineToState($state, $fromMeta);
                }
            }

            try {
                $resolved = $this->articleGenerationInput->resolveForArticle($article);
                $raw = trim((string) ($resolved->rawArtifact ?? ''));
                if ($raw !== '' && $this->articleGenerationInput->isValidArtifact($raw)) {
                    return $this->applySeededOutlineToState($state, $raw);
                }
            } catch (\Throwable) {
                // fail closed to empty — caller throws Vietnamese message
            }
        }

        return '';
    }

    private function applySeededOutlineToState(WorkflowExecutionState $state, string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        $state->meta['direct_publish_outline_markdown'] = $markdown;
        $state->lastPromptOutput = $markdown;
        $parsed = $this->workflowParser->parseOutline($markdown);
        if ($parsed !== []) {
            $state->setParsedOutline($parsed);
        }

        return $markdown;
    }

    private function isUsablePlainOutline(string $markdown): bool
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return false;
        }

        if (preg_match('/^#{1,6}\s+\S+/mu', $markdown) === 1) {
            return mb_strlen($markdown) >= 8;
        }

        return mb_strlen($markdown) >= 40;
    }

    /**
     * @deprecated Use seedOutlineStateForContentRerun — kept for callers/tests that pass article only.
     *
     * @return string Outline markdown đã seed (có thể rỗng)
     */
    private function seedOutlineStateFromArticle(WorkflowExecutionState $state, ?SeoArticle $article): string
    {
        $context = new TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: null,
            variables: [],
            summary: 'seed-outline',
        );

        return $this->seedOutlineStateForContentRerun($state, $context);
    }

    /**
     * Khi skip node trước content: gắn outline vào nodeOutputs để resolveInputForNode / hook `input` có dữ liệu.
     *
     * @param  array<string, mixed>  $node
     */
    private function hydrateSkippedNodeWithOutline(array $node, WorkflowExecutionState $state, string $markdown): void
    {
        $nodeId = trim((string) ($node['id'] ?? ''));
        if ($nodeId === '' || $markdown === '') {
            return;
        }

        $type = (string) ($node['type'] ?? '');
        if ($type === 'prompt') {
            $state->nodeOutputs[$nodeId] = [
                'out_main' => $markdown,
                'out_outline' => $markdown,
            ];
            $state->lastPromptOutput = $markdown;

            return;
        }

        if ($type !== 'filter') {
            return;
        }

        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $filterType = (string) ($data['filterType'] ?? $data['filter_type'] ?? $data['type'] ?? '');
        // Explicit filter type only — không title heuristic.
        $isOutlineFilter = $filterType === 'parse_outline'
            || str_contains($filterType, 'outline');

        if (! $isOutlineFilter) {
            return;
        }

        $parsed = $this->workflowParser->parseOutline($markdown);
        if ($parsed !== []) {
            $state->setParsedOutline($parsed);
        }
        $state->meta['direct_publish_outline_markdown'] = $markdown;
        $state->nodeOutputs[$nodeId] = ['out_main' => $markdown];
        $state->lastPromptOutput = $markdown;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orderedNodesForTask(SeoTask $task): array
    {
        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        $edges = is_array($flow['edges'] ?? null) ? $flow['edges'] : [];

        if ($nodes === []) {
            throw new \InvalidArgumentException('Quy trình chưa có sơ đồ (flow). Mở Builder để thiết kế.');
        }

        return $this->deferReviewActionsToEnd($this->orderedNodes($nodes, $edges));
    }

    /**
     * Action «Đăng bình luận / review» tự nhận bài viết đích từ state (bài vừa tạo/cập nhật),
     * nên phải chạy SAU action lưu bài — kể cả khi nhánh review nằm tách rời trong sơ đồ
     * (in-degree 0 → thứ tự topo có thể xếp nó chạy trước khi bài được tạo).
     *
     * @param  list<array<string, mixed>>  $ordered
     * @return list<array<string, mixed>>
     */
    private function deferReviewActionsToEnd(array $ordered): array
    {
        $reviewActions = [];
        $others = [];

        foreach ($ordered as $node) {
            $type = (string) ($node['type'] ?? '');
            $isReviewAction = $type === 'action'
                && (string) ($node['data']['actionType'] ?? '') === 'post_comment_review';
            $isEnd = $type === 'end';

            if ($isReviewAction || $isEnd) {
                $reviewActions[] = $node;
            } else {
                $others[] = $node;
            }
        }

        return array_merge($others, $reviewActions);
    }

    private function initialState(TaskTestContext $context): WorkflowExecutionState
    {
        $state = new WorkflowExecutionState;
        $state->article = $context->article;

        // TYPE_REWRITE: input đã là raw outline artifact — seed meta (GIỮ marker, không strip).
        if (
            $context->projectTaskType === SeoProjectTask::TYPE_REWRITE
            && filled($context->variables['article_generation_source'] ?? $context->variables['outline_id'] ?? null)
        ) {
            $outline = trim((string) ($context->variables['input'] ?? ''));
            if ($outline !== '' && $this->articleGenerationInput->isValidArtifact($outline)) {
                $state->meta['direct_publish_outline_markdown'] = $outline;
            }
        }

        return $state;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function buildStateFromSteps(array $steps, TaskTestContext $context): WorkflowExecutionState
    {
        $state = $this->initialState($context);

        foreach ($steps as $step) {
            $this->applyCompletedStepToState($step, $state);
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function applyCompletedStepToState(array $step, WorkflowExecutionState $state): void
    {
        $type = (string) ($step['type'] ?? '');

        if ($type === 'prompt' && filled($step['output'] ?? null)) {
            $nodeId = (string) ($step['node_id'] ?? '');
            $output = (string) $step['output'];
            $state->lastPromptOutput = $output;

            $outputs = is_array($step['outputs'] ?? null) ? $step['outputs'] : ['out_main' => $output];
            if ($nodeId !== '') {
                $state->nodeOutputs[$nodeId] = array_map(
                    static fn ($value): string => is_string($value) ? $value : (string) $value,
                    $outputs,
                );
            }

            $outlineMarkdown = trim((string) ($step['outline_markdown'] ?? ''));
            if ($outlineMarkdown === '' && (bool) ($step['persists_as_outline'] ?? false)) {
                $outlineMarkdown = trim($output);
            }
            if ($outlineMarkdown === '') {
                $outlineMarkdown = trim((string) ($outputs['out_outline'] ?? ''));
            }
            if ($outlineMarkdown !== '') {
                // Giữ raw artifact (có marker) — không strip trước content assembler.
                $state->meta['direct_publish_outline_markdown'] = trim($outlineMarkdown);
            }
        }

        if ($type === 'user_input') {
            $nodeId = (string) ($step['node_id'] ?? '');
            $output = trim((string) ($step['output'] ?? ''));
            if ($nodeId !== '' && $output !== '') {
                $state->nodeOutputs[$nodeId] = is_array($step['outputs'] ?? null)
                    ? array_map(
                        static fn ($value): string => is_string($value) ? $value : (string) $value,
                        $step['outputs'],
                    )
                    : ['out_input' => $output, 'out_main' => $output];
            }
        }

        if (in_array($type, ['article', 'article_filter'], true)) {
            $nodeId = (string) ($step['node_id'] ?? '');
            if ($nodeId !== '' && is_array($step['outputs'] ?? null)) {
                $state->nodeOutputs[$nodeId] = array_map(
                    static fn ($value): string => is_string($value) ? $value : (string) $value,
                    $step['outputs'],
                );
            }
        }

        if ($type === 'filter' && ($step['status'] ?? '') === 'completed') {
            $nodeId = (string) ($step['node_id'] ?? '');
            if ($nodeId !== '' && filled($step['output'] ?? null)) {
                $state->nodeOutputs[$nodeId] = ['out_main' => (string) $step['output']];
            }

            $parsed = $step['parsed'] ?? null;
            if (! is_array($parsed)) {
                return;
            }

            $filterType = (string) ($step['filter_type'] ?? '');
            if ($filterType === 'parse_outline') {
                $state->setParsedOutline($parsed);
                $outlineMarkdown = trim((string) ($step['outline_markdown'] ?? ''));
                if ($outlineMarkdown !== '') {
                    $state->meta['direct_publish_outline_markdown'] = $outlineMarkdown;
                }
            } elseif ($filterType === 'parse_keywords') {
                /** @var array<string, list<string>> $parsed */
                $state->setParsedKeywords($parsed);
            } elseif ($filterType === 'parse_faq') {
                $state->setParsedFaqs($parsed);
            }

            if (is_array($step['seo_score'] ?? null)) {
                $state->setSeoScoreData($step['seo_score']);
            }
        }

        if ($type === 'action' && is_numeric($step['article_id'] ?? null)) {
            $article = SeoArticle::query()->find((int) $step['article_id']);
            if ($article instanceof SeoArticle) {
                $state->article = $article;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function executeNode(
        array $node,
        TaskTestContext $context,
        WorkflowExecutionState $state,
        array $edges = [],
    ): array {
        $type = (string) ($node['type'] ?? '');
        $nodeId = (string) ($node['id'] ?? '');
        $title = (string) ($node['title'] ?? $type);
        $variables = $context->variables;

        if ($type === 'article') {
            $outputs = ['out_main' => $this->resolveKeywordOrTitle($variables)];
            $state->nodeOutputs[$nodeId] = $outputs;

            return [
                'node_id' => $nodeId,
                'type' => $type,
                'title' => $title,
                'status' => 'ok',
                'message' => $context->summary,
                'outputs' => $outputs,
            ];
        }

        if ($type === 'user_input') {
            $input = trim((string) ($variables['input'] ?? $variables['user_brief'] ?? ''));
            $outputs = [
                'out_input' => $input,
                'out_main' => $input,
            ];
            $state->nodeOutputs[$nodeId] = $outputs;

            return [
                'node_id' => $nodeId,
                'type' => $type,
                'title' => $title,
                'status' => 'ok',
                'message' => $input !== ''
                    ? 'Đã nhận biến {{input}} từ editor / test.'
                    : 'Chưa có nội dung {{input}} (nhập ở panel AI ảnh & video hoặc test input).',
                'output' => $input,
                'outputs' => $outputs,
            ];
        }

        if ($type === 'end') {
            return [
                'node_id' => $nodeId,
                'type' => $type,
                'title' => $title,
                'status' => 'ok',
                'message' => 'Điểm kết thúc quy trình (tượng trưng).',
            ];
        }

        if ($type === 'article_filter') {
            $outputs = $this->buildArticleFilterNodeOutputs($variables);
            $state->nodeOutputs[$nodeId] = $outputs;

            return [
                'node_id' => $nodeId,
                'type' => $type,
                'title' => $title,
                'status' => 'ok',
                'message' => 'Đã áp dụng cấu hình lọc bài viết.',
                'outputs' => $outputs,
            ];
        }

        if ($type === 'filter') {
            return $this->executeFilterNode($node, $state, $edges);
        }

        if ($type === 'action') {
            return $this->executeActionNode($node, $context, $state, $edges);
        }

        if ($type === 'prompt') {
            $promptId = $node['data']['promptId'] ?? null;
            $prompt = $this->resolvePrompt($promptId);

            if ($prompt === null) {
                return [
                    'node_id' => $nodeId,
                    'type' => $type,
                    'title' => $title,
                    'status' => 'failed',
                    'message' => $this->missingPromptMessage($promptId),
                ];
            }

            if ($this->isProductGalleryPromptNode($nodeId, $prompt, $edges)) {
                if (! $this->isProductWorkflowContext($context, $state)) {
                    return [
                        'node_id' => $nodeId,
                        'type' => $type,
                        'title' => $title,
                        'status' => 'skipped',
                        'skip_reason' => 'SKIPPED_NOT_APPLICABLE',
                        'prompt_id' => $prompt->id,
                        'prompt_name' => (string) $prompt->name,
                        'message' => 'SKIPPED_NOT_APPLICABLE — product gallery không áp dụng cho Article.',
                    ];
                }

                if (! $this->shouldRunProductGalleryPrompt($context, $state)) {
                    return [
                        'node_id' => $nodeId,
                        'type' => $type,
                        'title' => $title,
                        'status' => 'skipped',
                        'prompt_id' => $prompt->id,
                        'prompt_name' => (string) $prompt->name,
                        'message' => 'Bỏ qua prompt product gallery vì gallery_ready đã true.',
                    ];
                }
            }

            try {
                if ($this->isArticleContentGenerationPrompt($node, $prompt)) {
                    $outlineFromWorkflow = $this->resolveOutlineArtifactForPrompt(
                        $nodeId,
                        $edges,
                        $state,
                        $context,
                        $variables,
                    );

                    // DEPRECATED COMPATIBILITY ONLY — chỉ khi Hook thật sự là rewrite.
                    try {
                        $binding = PromptHookBinding::tryFromPrompt($prompt);
                        $hookKey = trim((string) ($binding?->hookKey ?? ''));
                        if (! $this->shouldBlockInheritedGenerationArtifacts($context, $variables)
                            && $this->legacyRewriteAdapter->isLegacyRewriteHook($hookKey)
                            && ArticleWritingSourceType::tryFromMixed(
                                $variables['article_writing_source_type'] ?? $variables['source_type'] ?? null,
                            ) === null
                        ) {
                            $this->legacyRewriteAdapter->logLegacyAdapterUsed(
                                caller: self::class.'::runPromptNode',
                                articleId: ($state->article ?? $context->article) !== null
                                    ? (int) ($state->article ?? $context->article)->getKey()
                                    : null,
                                mappedSourceType: ArticleWritingSourceType::ExistingArticle->value,
                            );
                            $variables['legacy_rewrite_adapter'] = true;
                            $variables['legacy_caller'] = self::class;
                            $variables['article_writing_source_type'] = ArticleWritingSourceType::ExistingArticle->value;
                            $variables['source_type'] = ArticleWritingSourceType::ExistingArticle->value;
                        }
                    } catch (\InvalidArgumentException) {
                        // ignore
                    }

                    $assembled = $this->articleWritingAssembler->assembleForPrompt(
                        $variables,
                        $context,
                        $outlineFromWorkflow,
                    );
                    if ($assembled !== null) {
                        // Workflow content node owns Prompt — không Settings binding song song.
                        $variables = $assembled['variables'];
                        $variables['prompt_owner_type'] = 'workflow_node';
                        $variables['prompt_owner_id'] = $nodeId;
                        $variables['prompt_id'] = (int) $prompt->id;
                        $variables['hook_key'] = ArticleWritingExecutionService::HOOK_KEY;
                        $variables['workflow_node_title'] = $title;
                        $roleRaw = is_array($node['data'] ?? null)
                            ? trim((string) ($node['data']['execution_role'] ?? ''))
                            : '';
                        if ($roleRaw !== '') {
                            $variables['execution_role'] = $roleRaw;
                        }
                        $assembled['variables'] = $variables;
                    }
                    if ($assembled === null) {
                        $sourceType = ArticleWritingSourceType::tryFromMixed(
                            $variables['article_writing_source_type'] ?? $variables['source_type'] ?? null,
                        );
                        $failMessage = $sourceType === ArticleWritingSourceType::ExistingArticle
                            ? 'Bài viết không có nội dung để viết lại toàn bộ.'
                            : ($sourceType === ArticleWritingSourceType::Brief
                                ? 'Thiếu tiêu đề / từ khóa / mô tả để viết bài từ brief.'
                                : ArticleGenerationInputResolver::REJECT_MESSAGE);

                        return [
                            'node_id' => $nodeId,
                            'type' => $type,
                            'title' => $title,
                            'status' => 'failed',
                            'prompt_id' => $prompt->id,
                            'prompt_name' => (string) $prompt->name,
                            'message' => $failMessage,
                        ];
                    }
                    $variables = $assembled['variables'];
                    $input = (string) $variables['input'];
                    if ($assembled['writing']->sourceType === ArticleWritingSourceType::Outline) {
                        $state->meta['direct_publish_outline_markdown'] = $assembled['writing']->input;
                    }
                } else {
                    $input = $this->resolveInputForNode($nodeId, $edges, $state);
                    if ($input === '') {
                        $input = trim((string) ($state->meta['direct_publish_outline_markdown'] ?? ''));
                    }
                    if ($input === '') {
                        $input = trim((string) ($state->lastPromptOutput ?? ''));
                    }
                    if ($input !== '') {
                        $variables['input'] = $input;
                    }
                }

                $reused = $this->existingAiOutput->resolve(
                    $node,
                    $prompt,
                    $state->article ?? $context->article,
                    allowReuse: $this->shouldReuseExistingAiOutput($context),
                );
                if ($reused !== null) {
                    $output = $reused['output'];
                    $state->lastPromptOutput = $output;
                    $state->nodeOutputs[$nodeId] = $this->buildPromptNodeOutputs($prompt, $output, $state);

                    $outlinePersistedMarkdown = '';
                    if ($reused['type'] === WorkflowExistingAiOutputService::TYPE_OUTLINE) {
                        $outlinePersistedMarkdown = $this->captureOutlinePromptOutput($node, $prompt, $output, $state);
                    } elseif ($reused['type'] === WorkflowExistingAiOutputService::TYPE_CONTENT) {
                        $state->meta['preserve_existing_article_body'] = true;
                    }

                    return [
                        'node_id' => $nodeId,
                        'type' => $type,
                        'title' => $title,
                        'status' => 'skipped',
                        'prompt_id' => $prompt->id,
                        'prompt_name' => (string) $prompt->name,
                        'hook_key' => $this->promptHookKey($prompt),
                        'execution_role' => $this->nodeExecutionRoleValue($node),
                        'output' => $output,
                        'outputs' => $state->nodeOutputs[$nodeId],
                        'outline_markdown' => $outlinePersistedMarkdown !== '' ? $outlinePersistedMarkdown : null,
                        'persists_as_outline' => $outlinePersistedMarkdown !== '',
                        'message' => $reused['message'],
                    ];
                }

                $model = trim((string) ($node['data']['aiModel'] ?? ''));
                $imageTool = \Omnichannel\Addons\Media\Support\ImageToolType::fromMixed($prompt->tools ?? 'default');
                $isImagePipeline = $imageTool->isImagePipeline();

                $hookBinding = null;
                try {
                    $hookBinding = PromptHookBinding::tryFromPrompt($prompt);
                } catch (\InvalidArgumentException $exception) {
                    return [
                        'node_id' => $nodeId,
                        'type' => $type,
                        'title' => $title,
                        'status' => 'failed',
                        'prompt_id' => $prompt->id,
                        'prompt_name' => (string) $prompt->name,
                        'message' => $exception->getMessage(),
                    ];
                }

                if ($hookBinding !== null && ! $isImagePipeline) {
                    $nodeData = is_array($node['data'] ?? null) ? $node['data'] : [];
                    $contextExtras = [
                        'site_id' => $this->resolveMediaContextSiteId($context, $state),
                        'article_id' => $state->article?->id ?? $context->article?->id,
                        'node_id' => $nodeId,
                        'task_id' => null,
                        'locale' => $variables['language'] ?? $variables['locale'] ?? null,
                    ];

                    if ($this->isOutlineRoleNode($node, $hookBinding->hookKey)) {
                        $splitResult = $this->outlineSplitExecutor->execute(
                            $nodeData,
                            $prompt,
                            $variables,
                            $contextExtras,
                        );

                        if (($splitResult['status'] ?? '') !== 'completed') {
                            return [
                                'node_id' => $nodeId,
                                'type' => $type,
                                'title' => $title,
                                'status' => 'failed',
                                'prompt_id' => $prompt->id,
                                'prompt_name' => (string) $prompt->name,
                                'hook_key' => $splitResult['hook_key'] ?? ArticleOutlineVocabularySplitExecutor::OUTLINE_STRUCTURE_HOOK,
                                'hook_version' => $splitResult['hook_version'] ?? '0.1.0',
                                'execution_source' => $splitResult['execution_source'] ?? 'split_outline_vocabulary',
                                'execution_role' => $this->nodeExecutionRoleValue($node),
                                'message' => (string) ($splitResult['message'] ?? 'Outline split failed.'),
                                'result_id' => $splitResult['outline_result']['prompt_result_id']
                                    ?? ($splitResult['prompt_result_ids'][0] ?? null),
                                'outline_subtask' => isset($splitResult['vocabulary_result'])
                                    ? 'vocabulary_failed'
                                    : 'outline_failed',
                            ];
                        }

                        $output = trim((string) ($splitResult['output'] ?? ''));
                        $outlinePersistedMarkdown = '';
                        if ($output !== '') {
                            $output = $this->applyPromptPostProcessing($prompt, $output);
                            $state->lastPromptOutput = $output;
                            $state->nodeOutputs[$nodeId] = $this->buildPromptNodeOutputsFromHook(
                                $prompt,
                                $output,
                                is_array($splitResult['ports'] ?? null) ? $splitResult['ports'] : [],
                                is_array($splitResult['sections'] ?? null) ? $splitResult['sections'] : [],
                                $state,
                            );
                            $this->refreshWorkflowSeoScore($state, $output);
                            $outlinePersistedMarkdown = $this->captureOutlinePromptOutput($node, $prompt, $output, $state);
                        }

                        return [
                            'node_id' => $nodeId,
                            'type' => $type,
                            'title' => $title,
                            'status' => 'completed',
                            'prompt_id' => $prompt->id,
                            'prompt_name' => (string) $prompt->name,
                            'hook_key' => $splitResult['hook_key'],
                            'hook_version' => $splitResult['hook_version'],
                            'execution_role' => $this->nodeExecutionRoleValue($node),
                            'execution_source' => $splitResult['execution_source'],
                            'correlation_id' => $splitResult['correlation_id'],
                            'ai_model' => $splitResult['ai_model'],
                            'raw_model_used' => $splitResult['ai_model'],
                            'tools' => $imageTool->value,
                            'input_used' => $input !== '' ? mb_substr($input, 0, 120).(mb_strlen($input) > 120 ? '…' : '') : null,
                            'output' => $output,
                            'outputs' => $state->nodeOutputs[$nodeId] ?? [],
                            'outline_markdown' => $outlinePersistedMarkdown !== '' ? $outlinePersistedMarkdown : null,
                            'persists_as_outline' => $outlinePersistedMarkdown !== '',
                            'artifact_type' => $outlinePersistedMarkdown !== ''
                                ? WorkflowArtifactType::ArticleOutline->value
                                : null,
                            'result_id' => $splitResult['prompt_result_ids'][1]
                                ?? $splitResult['prompt_result_ids'][0]
                                ?? null,
                            'prompt_result_ids' => $splitResult['prompt_result_ids'],
                            'duration_ms' => $splitResult['duration_ms'],
                            'message' => (string) ($splitResult['message'] ?? 'Split outline completed.'),
                        ];
                    }

                    try {
                        $hookResult = $this->hookBindingExecutor->execute(
                            $prompt,
                            $variables,
                            $contextExtras,
                        );
                    } catch (PromptHookFailure $exception) {
                        $mapped = $this->hookFailureMapper->map(
                            $exception,
                            $hookBinding->hookKey,
                            $hookBinding->hookVersion,
                            null,
                        );

                        return [
                            'node_id' => $nodeId,
                            'type' => $type,
                            'title' => $title,
                            'status' => 'failed',
                            'prompt_id' => $prompt->id,
                            'prompt_name' => (string) $prompt->name,
                            'hook_key' => $hookBinding->hookKey,
                            'hook_version' => $hookBinding->hookVersion,
                            'execution_source' => 'explicit_hook_binding',
                            'message' => $mapped['body'],
                            'failure_category' => $mapped['category'],
                            // AI đã chạy trước khi validator fail — giữ result_id để /prompts link được.
                            'result_id' => $exception->promptResultId(),
                        ];
                    }

                    $output = trim((string) ($hookResult['output'] ?? ''));
                    $outlinePersistedMarkdown = '';
                    if ($output !== '') {
                        $output = $this->applyPromptPostProcessing($prompt, $output);
                        $state->lastPromptOutput = $output;
                        $state->nodeOutputs[$nodeId] = $this->buildPromptNodeOutputsFromHook(
                            $prompt,
                            $output,
                            is_array($hookResult['ports'] ?? null) ? $hookResult['ports'] : [],
                            is_array($hookResult['sections'] ?? null) ? $hookResult['sections'] : [],
                            $state,
                        );
                        $this->refreshWorkflowSeoScore($state, $output);
                        $outlinePersistedMarkdown = $this->captureOutlinePromptOutput($node, $prompt, $output, $state);
                    }

                    if ($output !== '' && $this->shouldRegisterArticleContentFromPrompt($node, (string) ($hookResult['hook_key'] ?? ''))) {
                        $this->registerArticleContentFromPromptOutput(
                            $node,
                            $prompt,
                            trim($output),
                            $state,
                            $context,
                            trim((string) ($hookResult['hook_key'] ?? '')),
                        );
                        // Input của content = raw outline artifact — giữ nguyên marker ở outline slot.
                        $outlineSource = trim($input);
                        if ($outlineSource !== '' && $this->articleGenerationInput->isValidArtifact($outlineSource)) {
                            $state->meta['direct_publish_outline_markdown'] = $outlineSource;
                        }
                    }

                    return [
                        'node_id' => $nodeId,
                        'type' => $type,
                        'title' => $title,
                        'status' => 'completed',
                        'prompt_id' => $prompt->id,
                        'prompt_name' => (string) $prompt->name,
                        'hook_key' => $hookResult['hook_key'],
                        'hook_version' => $hookResult['hook_version'],
                        'execution_role' => $this->nodeExecutionRoleValue($node),
                        'execution_source' => $hookResult['execution_source'],
                        'correlation_id' => $hookResult['correlation_id'],
                        'ai_model' => $hookResult['model'],
                        'raw_model_used' => $hookResult['model'],
                        'tools' => $imageTool->value,
                        'input_used' => $input !== '' ? mb_substr($input, 0, 120).(mb_strlen($input) > 120 ? '…' : '') : null,
                        'output' => $output,
                        'outputs' => $state->nodeOutputs[$nodeId] ?? [],
                        'outline_markdown' => $outlinePersistedMarkdown !== '' ? $outlinePersistedMarkdown : null,
                        'persists_as_outline' => $outlinePersistedMarkdown !== '',
                        'artifact_type' => $outlinePersistedMarkdown !== ''
                            ? WorkflowArtifactType::ArticleOutline->value
                            : ($this->shouldRegisterArticleContentFromPrompt($node, (string) ($hookResult['hook_key'] ?? ''))
                                ? WorkflowArtifactType::ArticleContent->value
                                : null),
                        'result_id' => $hookResult['prompt_result_id'],
                        'duration_ms' => $hookResult['duration_ms'],
                        'actual_word_count' => $hookResult['actual_word_count'] ?? null,
                        'minimum_acceptable_words' => $hookResult['minimum_acceptable_words'] ?? null,
                        'target_article_length' => $hookResult['target_article_length'] ?? null,
                        'length_validation_result' => $hookResult['length_validation_result'] ?? null,
                        'message' => 'Prompt Hook completed ('.$hookResult['hook_key'].'@'.$hookResult['hook_version'].').',
                    ];
                }

                // Khớp Test Prompt (image): compile full + ImageRoutingStrategy.
                // Không chạy chain planner (Flash text) — đó là nguyên nhân task hiện gemini-3-flash-preview
                // trong khi Test Prompt ra imagen / Nano Banana.
                // Node.aiModel category (gemini_flash, …) chỉ áp dụng cho prompt text.
                $result = PromptMediaPersistContext::using(
                    $this->resolveMediaContextSiteId($context, $state),
                    $state->article?->id ?? $context->article?->id,
                    (int) $prompt->id,
                    fn () => $this->promptRunner->run(
                        $prompt,
                        $variables,
                        $isImagePipeline
                            ? null
                            : ($model !== '' ? $model : null),
                        isTaskMode: true,
                        runFullDependentChain: ! $isImagePipeline,
                    ),
                );
                $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
                $rawModelUsed = trim((string) (
                    $snapshot['render_model']
                        ?? $snapshot['raw_model_used']
                        ?? $snapshot['planner_model']
                        ?? ''
                ));
                $output = trim((string) ($result->output_text ?? ''));
                if ($output !== '') {
                    $output = $this->applyPromptPostProcessing($prompt, $output);
                }

                $outlinePersistedMarkdown = '';
                if ($output !== '') {
                    $state->lastPromptOutput = $output;
                    $state->nodeOutputs[$nodeId] = $this->buildPromptNodeOutputs($prompt, $output, $state);
                    $this->refreshWorkflowSeoScore($state, $output);
                    $outlinePersistedMarkdown = $this->captureOutlinePromptOutput($node, $prompt, $output, $state);
                }

                if (
                    $output !== ''
                    && $result->status === 'completed'
                    && $this->isProductGalleryPromptNode($nodeId, $prompt, $edges)
                    && $this->isProductWorkflowContext($context, $state)
                ) {
                    $workflowArticle = $this->resolveWorkflowArticle($context, $state);
                    if ($workflowArticle instanceof SeoArticle) {
                        $this->runProductGalleryMode1FromWorkflowOutput($workflowArticle, $prompt, $output);
                    }
                }

                if ($this->shouldMergeOutlineToSave($node) && trim($output) !== '') {
                    // «Viết bài theo dàn ý»: chỉ đăng ký typed article_content — không fallback outline.
                    $this->registerArticleContentFromPromptOutput(
                        $node,
                        $prompt,
                        trim($output),
                        $state,
                        $context,
                        $this->promptHookKey($prompt),
                    );

                    $outlineSource = trim($input);
                    if ($outlineSource !== '' && $this->articleGenerationInput->isValidArtifact($outlineSource)) {
                        $state->meta['direct_publish_outline_markdown'] = $outlineSource;
                    }
                }

                return [
                    'node_id' => $nodeId,
                    'type' => $type,
                    'title' => $title,
                    'status' => $result->status === 'completed' ? 'completed' : 'failed',
                    'prompt_id' => $prompt->id,
                    'prompt_name' => (string) $prompt->name,
                    'hook_key' => $this->promptHookKey($prompt),
                    'execution_role' => $this->nodeExecutionRoleValue($node),
                    'ai_model' => $isImagePipeline
                        ? null
                        : ($model !== '' ? $model : null),
                    'raw_model_used' => $rawModelUsed !== '' ? $rawModelUsed : null,
                    'render_model' => trim((string) ($snapshot['render_model'] ?? '')) ?: null,
                    'planner_model' => trim((string) ($snapshot['planner_model'] ?? '')) ?: null,
                    'tools' => $imageTool->value,
                    'input_used' => $input !== '' ? mb_substr($input, 0, 120).(mb_strlen($input) > 120 ? '…' : '') : null,
                    'output' => $output,
                    'outputs' => $state->nodeOutputs[$nodeId] ?? [],
                    'outline_markdown' => $outlinePersistedMarkdown !== '' ? $outlinePersistedMarkdown : null,
                    'persists_as_outline' => $outlinePersistedMarkdown !== '',
                    'result_id' => $result->id,
                    'message' => $result->status === 'completed'
                        ? 'Chạy prompt thành công.'
                        : (string) ($result->error_message ?? 'Prompt thất bại.'),
                ];
            } catch (PromptRunException $exception) {
                return [
                    'node_id' => $nodeId,
                    'type' => $type,
                    'title' => $title,
                    'status' => 'failed',
                    'prompt_id' => $prompt->id,
                    'prompt_name' => (string) $prompt->name,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'node_id' => $nodeId,
            'type' => $type,
            'title' => $title,
            'status' => 'skipped',
            'message' => 'Loại node không hỗ trợ: '.$type,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function executeFilterNode(array $node, WorkflowExecutionState $state, array $edges): array
    {
        $nodeId = (string) ($node['id'] ?? '');
        $title = (string) ($node['title'] ?? 'filter');
        $filterType = (string) ($node['data']['filterType'] ?? 'custom');
        $inputData = trim($this->resolveInputForNode($nodeId, $edges, $state));
        $filterTag = trim((string) ($node['data']['filterTag'] ?? ''));
        $tagKey = trim((string) ($node['data']['tagKey'] ?? ($filterTag === '__custom__'
            ? ($node['data']['customTag'] ?? '')
            : $filterTag)));

        if ($filterType === 'extract_segment') {
            if ($inputData === '') {
                return [
                    'node_id' => $nodeId,
                    'type' => 'filter',
                    'title' => $title,
                    'filter_type' => $filterType,
                    'status' => 'failed',
                    'message' => 'Không có dữ liệu đầu vào để bóc tách.',
                ];
            }

            if ($tagKey === '') {
                return [
                    'node_id' => $nodeId,
                    'type' => 'filter',
                    'title' => $title,
                    'filter_type' => $filterType,
                    'status' => 'failed',
                    'message' => 'Chưa cấu hình tên bộ lọc cần bóc tách.',
                ];
            }

            $extract = $this->tagExtractor->extractSegment($inputData, $tagKey);
            $normalizedTag = strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', $tagKey) ?? '');
            $normalizedTag = trim($normalizedTag, '_');
            if ($normalizedTag === '') {
                $normalizedTag = 'UNKNOWN_TAG';
            }

            if (! $extract['matched']) {
                $state->meta['workflow_warnings'][] = sprintf('Không tìm thấy segment cho tag "%s".', $tagKey);
                $state->nodeOutputs[$nodeId] = ['out_main' => ''];
                $state->meta['extracted_segments'][$normalizedTag] = '';

                return [
                    'node_id' => $nodeId,
                    'type' => 'filter',
                    'title' => $title,
                    'filter_type' => $filterType,
                    'status' => 'completed',
                    'message' => sprintf('Không tìm thấy segment [%s], trả về rỗng.', $normalizedTag),
                    'parsed' => ['tag' => $normalizedTag, 'content' => ''],
                    'output' => '',
                    'outputs' => ['out_main' => ''],
                ];
            }

            $segmentContent = trim((string) ($extract['content'] ?? ''));
            $state->meta['extracted_segments'][$normalizedTag] = $segmentContent;
            $state->nodeOutputs[$nodeId] = ['out_main' => $segmentContent];

            return [
                'node_id' => $nodeId,
                'type' => 'filter',
                'title' => $title,
                'filter_type' => $filterType,
                'status' => 'completed',
                'message' => sprintf('Đã bóc tách segment [%s].', $normalizedTag),
                'parsed' => ['tag' => $normalizedTag, 'content' => $segmentContent],
                'output' => $segmentContent,
                'outputs' => ['out_main' => $segmentContent],
            ];
        }

        if ($filterType === 'custom') {
            return [
                'node_id' => $nodeId,
                'type' => 'filter',
                'title' => $title,
                'filter_type' => $filterType,
                'status' => 'skipped',
                'message' => 'Quy tắc lọc tùy chỉnh chưa được hỗ trợ trong chạy thử.',
            ];
        }

        if ($inputData === '') {
            return [
                'node_id' => $nodeId,
                'type' => 'filter',
                'title' => $title,
                'filter_type' => $filterType,
                'status' => 'failed',
                'message' => 'Không có kết quả Markdown từ bước Prompt trước đó.',
            ];
        }

        if ($filterType === 'parse_outline') {
            $parsedResult = $this->workflowParser->parseOutline($inputData);
            $state->setParsedOutline($parsedResult);
            // Giữ raw nếu là full artifact; fallback cleaned chỉ cho parse UI.
            $state->meta['direct_publish_outline_markdown'] = $this->articleGenerationInput->isValidArtifact($inputData)
                ? trim($inputData)
                : $this->cleanWorkflowOutlineMarkdown($inputData);
            $this->refreshWorkflowSeoScore($state, $inputData);
            $jsonOutput = json_encode($parsedResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $state->nodeOutputs[$nodeId] = ['out_main' => $jsonOutput];

            $response = $this->filterStepResponse(
                $nodeId,
                $title,
                $filterType,
                'Đã bóc tách dàn ý ('.count($parsedResult).' mục H2/H3).',
                $parsedResult,
                $jsonOutput,
                $state,
            );
            $response['outline_markdown'] = $state->meta['direct_publish_outline_markdown'];

            return $response;
        }

        if ($filterType === 'parse_keywords') {
            $parsedResult = $this->workflowParser->parseKeywords($inputData);
            $state->setParsedKeywords($parsedResult);
            $this->refreshWorkflowSeoScore($state, $inputData);
            $jsonOutput = json_encode($parsedResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $state->nodeOutputs[$nodeId] = ['out_main' => $jsonOutput];

            $keywordCount = array_sum(array_map('count', $parsedResult));

            return $this->filterStepResponse(
                $nodeId,
                $title,
                $filterType,
                'Đã bóc tách từ khóa ('.count($parsedResult).' nhóm, '.$keywordCount.' từ).',
                $parsedResult,
                $jsonOutput,
                $state,
            );
        }

        if ($filterType === 'parse_faq') {
            $parsedResult = $this->workflowParser->parseFaqsFromContent($inputData);
            $cleanedMarkdown = $this->workflowParser->removeFaqAndAppendShortcodeFromContent($inputData);
            $state->setParsedFaqs($parsedResult);
            $state->lastPromptOutput = $cleanedMarkdown;
            $this->refreshWorkflowSeoScore($state, $inputData);
            $jsonOutput = json_encode($parsedResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $state->nodeOutputs[$nodeId] = ['out_main' => $cleanedMarkdown];

            return $this->filterStepResponse(
                $nodeId,
                $title,
                $filterType,
                'Đã bóc tách FAQ ('.count($parsedResult).' câu) và chèn [omi_faq].',
                $parsedResult,
                $jsonOutput,
                $state,
            );
        }

        if ($filterType === 'score_seo') {
            $this->refreshWorkflowSeoScore($state, $inputData);
            $scoreData = is_array($state->meta['seo_score_data'] ?? null) ? $state->meta['seo_score_data'] : [];
            $total = (int) ($scoreData['total_score'] ?? 0);
            $jsonOutput = json_encode($scoreData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $state->nodeOutputs[$nodeId] = ['out_main' => $jsonOutput];

            return $this->filterStepResponse(
                $nodeId,
                $title,
                $filterType,
                'Đã chấm điểm SEO tự động (+'.$total.' điểm).',
                $scoreData,
                $jsonOutput,
                $state,
            );
        }

        return [
            'node_id' => $nodeId,
            'type' => 'filter',
            'title' => $title,
            'filter_type' => $filterType,
            'status' => 'skipped',
            'message' => 'Loại lọc không hỗ trợ: '.$filterType,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    /**
     * @param  list<array<string, mixed>>  $edges
     */
    private function executeActionNode(
        array $node,
        TaskTestContext $context,
        WorkflowExecutionState $state,
        array $edges,
    ): array {
        $nodeId = (string) ($node['id'] ?? '');
        $title = (string) ($node['title'] ?? 'action');
        $actionType = (string) ($node['data']['actionType'] ?? 'save_article');

        if ($actionType === 'post_comment_review') {
            return $this->executePostCommentReviewAction($node, $context, $state, $edges);
        }

        if ($actionType === 'save_vocabulary_research') {
            return $this->executeSaveVocabularyResearchAction($node, $context, $state);
        }

        $article = $state->article ?? $context->article;

        if ($this->isArticlePersistAction($actionType) && $article === null) {
            $article = $this->createArticleFromContext($context);
            if ($article === null) {
                return [
                    'node_id' => $nodeId,
                    'type' => 'action',
                    'title' => $title,
                    'action_type' => $actionType,
                    'status' => 'failed',
                    'message' => 'Không thể tạo bài viết: chưa có website/domain để gán bài.',
                ];
            }
            $state->article = $article;
        }

        if ($article === null) {
            return [
                'node_id' => $nodeId,
                'type' => 'action',
                'title' => $title,
                'action_type' => $actionType,
                'status' => 'failed',
                'message' => 'Không có bài viết đích để lưu meta.',
            ];
        }

        $this->persistProductPromptMeta($article, $context->variables);

        $messages = [];
        $preserveExistingBody = (bool) ($state->meta['preserve_existing_article_body'] ?? false);
        // Domain write consumes typed article_content ONLY — never lastPromptOutput / outline.
        $contentArtifact = $this->resolveTypedArtifact($state, WorkflowArtifactType::ArticleContent);
        $articleMarkdown = '';
        if (! $preserveExistingBody && $contentArtifact instanceof WorkflowTypedArtifact) {
            $articleMarkdown = trim($contentArtifact->payload);
        } elseif (! $preserveExistingBody) {
            $candidate = trim((string) ($state->meta['direct_publish_article_markdown'] ?? ''));
            if ($candidate !== ''
                && $this->artifactReusePolicy->isValidArticleContentPayload($candidate)
                && ! $this->artifactReusePolicy->looksLikeOutlineMarkerPayload($candidate)
            ) {
                $articleMarkdown = $candidate;
            }
        }

        if ($this->isArticlePersistAction($actionType) && ! $preserveExistingBody && $articleMarkdown === '') {
            return [
                'node_id' => $nodeId,
                'type' => 'action',
                'title' => $title,
                'action_type' => $actionType,
                'status' => 'blocked',
                'article_id' => $article->id,
                'message' => 'Không ghi nội dung vì bước Viết bài chưa tạo được article_content artifact hợp lệ.',
                'skip_reason' => 'content_artifact_missing',
            ];
        }

        if ($preserveExistingBody) {
            $messages[] = 'Giữ nguyên nội dung bài viết đã có.';
            $heldMarkdown = trim((string) ($state->meta['direct_publish_article_markdown'] ?? ''));
            if ($heldMarkdown !== '' && $this->artifactReusePolicy->isValidArticleContentPayload($heldMarkdown)) {
                $this->persistMetaDescriptionFromMarkdown($article, $heldMarkdown);
            }
        }

        if ($articleMarkdown !== '') {
            if ($this->artifactReusePolicy->looksLikeOutlineMarkerPayload($articleMarkdown)) {
                return [
                    'node_id' => $nodeId,
                    'type' => 'action',
                    'title' => $title,
                    'action_type' => $actionType,
                    'status' => 'blocked',
                    'article_id' => $article->id,
                    'message' => 'Không ghi nội dung vì bước Viết bài chưa tạo được article_content artifact hợp lệ.',
                    'skip_reason' => 'outline_cannot_satisfy_article_content',
                ];
            }

            try {
                $publish = $this->promptPublisher->publishArticle(
                    $article,
                    $articleMarkdown,
                    $context->variables,
                );
            } catch (\Throwable $exception) {
                // Không để lỗi đăng bài làm hỏng cả quy trình test → trả về bước «failed» có thông báo.
                return [
                    'node_id' => $nodeId,
                    'type' => 'action',
                    'title' => $title,
                    'action_type' => $actionType,
                    'status' => 'failed',
                    'article_id' => $article->id,
                    'message' => 'Lỗi khi lưu nội dung bài viết: '.$exception->getMessage(),
                ];
            }

            if (! ($publish['success'] ?? false)) {
                return [
                    'node_id' => $nodeId,
                    'type' => 'action',
                    'title' => $title,
                    'action_type' => $actionType,
                    'status' => 'failed',
                    'article_id' => $article->id,
                    'message' => (string) ($publish['message'] ?? 'Không thể lưu nội dung bài viết từ markdown.'),
                ];
            }

            $article = $article->fresh() ?? $article;
            $state->article = $article;
            $state->meta['article_markdown_published'] = true;
            $state->meta['article_content_write_provenance'] = [
                'artifact_type' => WorkflowArtifactType::ArticleContent->value,
                'workflow_node_id' => $contentArtifact?->workflowNodeId,
                'producer_hook_key' => $contentArtifact?->producerHookKey,
                'run_id' => $contentArtifact?->runId,
                'run_item_id' => $contentArtifact?->runItemId,
                'attempt' => $contentArtifact?->attempt,
            ];
            $messages[] = (string) ($publish['message'] ?? 'Đã lưu nội dung bài viết (tiêu đề + body + meta).');

            $outlineMarkdown = trim((string) ($state->meta['direct_publish_outline_markdown'] ?? ''));
            if ($outlineMarkdown !== '') {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'seo_article_outline'],
                    ['meta_value' => $outlineMarkdown],
                );
            }
        }

        $shouldAppendProductGallery = $this->isProductWorkflowContext($context, $state)
            && $this->shouldRunProductGalleryPrompt($context, $state);

        if ($shouldAppendProductGallery) {
            $galleryImagesCount = $this->appendGalleryImagesFromActionEdges($article, $nodeId, $edges, $state);
            if ($galleryImagesCount > 0) {
                $messages[] = sprintf('Đã thêm %d ảnh vào product gallery.', $galleryImagesCount);
            }
        }

        if ($this->isProductWorkflowContext($context, $state)) {
            $article->unsetRelation('articleMetas');
            $galleryReady = \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::isReadyOnArticle($article)
                || $this->articleMediaLocal->resolveProductAlbum($article) !== [];

            if ($galleryReady) {
                $mediaService = $this->articleMediaLocal;
                $fixedMediaCount = $this->quickFixProductGalleryMedia($article, $context, $mediaService);
                if ($fixedMediaCount > 0) {
                    $messages[] = sprintf('Đã fix slug/alt/title cho %d ảnh product gallery.', $fixedMediaCount);
                    $article->unsetRelation('articleMetas');
                }

                $galleryItems = $mediaService->resolveProductAlbum($article);
                if ($galleryItems !== []) {
                    $distributedCount = app(ArticleProductGalleryDistributeService::class)
                        ->distribute($article, $galleryItems);
                    if ($distributedCount > 0) {
                        $messages[] = sprintf('Đã rải %d ảnh vào các section.', $distributedCount);
                    }
                }
            } elseif (! $shouldAppendProductGallery) {
                $article->unsetRelation('articleMetas');
                $stillPending = ! \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::isReadyOnArticle($article)
                    && $this->articleMediaLocal->resolveProductAlbum($article) === [];
                $messages[] = $stillPending
                    ? 'Không có ảnh sản phẩm khả dụng để tạo gallery.'
                    : 'Giữ nguyên album hình ảnh sản phẩm đã có.';
            }
        }

        $savedKeys = $this->persistWorkflowMeta($article, $state);
        if ($savedKeys !== []) {
            $messages[] = 'Đã lưu meta: '.implode(', ', $savedKeys);
        }

        if ($this->keywordResearch->shouldSyncKeywords($actionType, $state)) {
            try {
                $sync = $this->syncKeywordResearchForArticle($article, $context, $state);
                $messages[] = $this->formatVocabularyResearchSyncMessage($sync);
            } catch (\InvalidArgumentException $exception) {
                if ($actionType === 'save_vocabulary_research') {
                    return [
                        'node_id' => $nodeId,
                        'type' => 'action',
                        'title' => $title,
                        'action_type' => $actionType,
                        'status' => 'failed',
                        'article_id' => $article->id,
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        }

        return [
            'node_id' => $nodeId,
            'type' => 'action',
            'title' => $title,
            'action_type' => $actionType,
            'status' => 'completed',
            'article_id' => $article->id,
            'message' => $messages === []
                ? 'Hành động hoàn tất (không có meta/từ khóa để lưu).'
                : implode(' ', $messages),
            'output' => json_encode($state->meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<array<string, mixed>>  $edges
     * @return array<string, mixed>
     */
    private function executePostCommentReviewAction(
        array $node,
        TaskTestContext $context,
        WorkflowExecutionState $state,
        array $edges,
    ): array {
        $nodeId = (string) ($node['id'] ?? '');
        $title = (string) ($node['title'] ?? 'action');
        $actionType = 'post_comment_review';

        $article = $state->article ?? $context->article;
        if (! $article instanceof SeoArticle) {
            return [
                'node_id' => $nodeId,
                'type' => 'action',
                'title' => $title,
                'action_type' => $actionType,
                'status' => 'failed',
                'message' => 'Không có bài viết đích để đăng review/bình luận.',
            ];
        }

        $input = $this->resolveInputForNode($nodeId, $edges, $state);
        if ($input === '') {
            $input = trim((string) ($state->lastPromptOutput ?? ''));
        }

        if ($input === '') {
            return [
                'node_id' => $nodeId,
                'type' => 'action',
                'title' => $title,
                'action_type' => $actionType,
                'status' => 'failed',
                'article_id' => $article->id,
                'message' => 'Không có kết quả AI để đăng review/bình luận.',
            ];
        }

        // Full cutover: local product reviews + automation events. No WP mid-workflow.
        $result = $this->commentReviewPublisher->storeLocalFromAiOutput($article->fresh() ?? $article, $input);

        return [
            'node_id' => $nodeId,
            'type' => 'action',
            'title' => $title,
            'action_type' => $actionType,
            'status' => ($result['success'] ?? false) ? 'completed' : 'failed',
            'article_id' => $article->id,
            'message' => (string) ($result['message'] ?? ''),
            'created_count' => (int) ($result['created_count'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function executeSaveVocabularyResearchAction(
        array $node,
        TaskTestContext $context,
        WorkflowExecutionState $state,
    ): array {
        $nodeId = (string) ($node['id'] ?? '');
        $title = (string) ($node['title'] ?? 'action');
        $actionType = 'save_vocabulary_research';

        $article = $state->article ?? $context->article;

        if ($article === null) {
            $article = $this->createArticleFromContext($context);
            if ($article === null) {
                return [
                    'node_id' => $nodeId,
                    'type' => 'action',
                    'title' => $title,
                    'action_type' => $actionType,
                    'status' => 'failed',
                    'message' => 'Không thể tạo bài viết đích để lưu nghiên cứu từ vựng.',
                ];
            }

            $state->article = $article;
        }

        $savedKeys = $this->persistWorkflowMeta($article, $state);

        try {
            $sync = $this->syncKeywordResearchForArticle($article, $context, $state);
        } catch (\InvalidArgumentException $exception) {
            $message = trim($exception->getMessage());
            if ($message !== '' && (
                str_contains(strtolower($message), 'vocabulary save failed')
                || str_contains(strtolower($message), 'save failed')
            )) {
                return [
                    'node_id' => $nodeId,
                    'type' => 'action',
                    'title' => $title,
                    'action_type' => $actionType,
                    'status' => 'failed',
                    'article_id' => $article->id,
                    'message' => $message,
                ];
            }

            // Rerun từ article thường skip bước parse keywords → không có data mới.
            // Không fail cả pipeline; giữ từ khóa hiện có trên bài.
            return [
                'node_id' => $nodeId,
                'type' => 'action',
                'title' => $title,
                'action_type' => $actionType,
                'status' => 'skipped',
                'article_id' => $article->id,
                'message' => 'Bỏ qua lưu từ khóa ngữ nghĩa — '.$exception->getMessage(),
            ];
        }

        $metaNote = $savedKeys !== [] ? ' Meta: '.implode(', ', $savedKeys).'.' : '';

        return [
            'node_id' => $nodeId,
            'type' => 'action',
            'title' => $title,
            'action_type' => $actionType,
            'status' => 'completed',
            'article_id' => $article->id,
            'message' => $this->formatVocabularyResearchSyncMessage($sync, true).$metaNote,
            'output' => json_encode([
                'topic_cluster' => $sync,
                'meta' => $state->meta,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ];
    }

    /**
     * @param  array{parent_id: int, parent_phrase: string, children_count: int, suggest_count?: int, tags_count?: int, ki_feedback?: array<string, mixed>}  $sync
     */
    private function formatVocabularyResearchSyncMessage(array $sync, bool $includeParentId = false): string
    {
        $parts = [];
        $childrenCount = (int) ($sync['children_count'] ?? 0);
        $suggestCount = (int) ($sync['suggest_count'] ?? 0);
        $tagsCount = (int) ($sync['tags_count'] ?? 0);
        $parentPhrase = trim((string) ($sync['parent_phrase'] ?? ''));
        $ki = is_array($sync['ki_feedback'] ?? null) ? $sync['ki_feedback'] : [];

        if ($ki !== []) {
            $parts[] = sprintf(
                'Related topics: %d discovered · %d ingested · %d filtered · %d duplicates',
                (int) ($ki['discovered'] ?? $suggestCount),
                (int) ($ki['ingested'] ?? 0),
                (int) ($ki['filtered'] ?? 0),
                (int) ($ki['duplicates'] ?? 0),
            );
        } elseif ($suggestCount > 0) {
            $parts[] = sprintf('%d gợi ý chủ đề (Related topics)', $suggestCount);
        }

        if ($parentPhrase !== '') {
            $clusterMessage = $includeParentId
                ? sprintf('cụm «%s» (#%d) + %d từ khóa con (Topic Cluster)', $parentPhrase, (int) ($sync['parent_id'] ?? 0), $childrenCount)
                : sprintf('cụm «%s» + %d từ khóa con', $parentPhrase, $childrenCount);
            $parts[] = $clusterMessage;
        }

        if ($tagsCount > 0) {
            $parts[] = sprintf('%d tag Holonymy', $tagsCount);
        }

        if ($parts === []) {
            return 'Đã lưu nghiên cứu từ vựng.';
        }

        return 'Đã lưu nghiên cứu từ vựng — '.implode('; ', $parts).'.';
    }

    /**
     * @return array{parent_id: int, parent_phrase: string, children_count: int, suggest_count: int, tags_count: int}
     */
    private function syncKeywordResearchForArticle(
        SeoArticle $article,
        TaskTestContext $context,
        WorkflowExecutionState $state,
    ): array {
        $groups = $this->keywordResearch->keywordGroupsFromState($state);
        $focusPhrase = $this->keywordResearch->resolveFocusPhrase($article, $context);

        $dispatcher = app(\Omnichannel\Addons\Agent\Automation\Contracts\BusinessActionDispatcher::class);
        $actionContext = \Omnichannel\Addons\Agent\Automation\Data\ActionContext::fromArray([
            'origin' => 'workflow.task_test_runner',
            'actor_id' => auth()->id() !== null ? (int) auth()->id() : null,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
        ]);

        $vocab = $dispatcher->dispatch(
            'keyword.vocabulary.save',
            [
                'article_id' => (int) $article->id,
                'keyword_groups' => $groups,
                'focus_phrase' => $focusPhrase,
                'prompt_result_id' => isset($state->meta['last_prompt_result_id'])
                    ? (int) $state->meta['last_prompt_result_id']
                    : null,
                'project_id' => isset($context->variables['project_id'])
                    ? (int) $context->variables['project_id']
                    : null,
                'project_task_id' => isset($context->variables['project_task_id'])
                    ? (int) $context->variables['project_task_id']
                    : (isset($context->variables['task_id']) ? (int) $context->variables['task_id'] : null),
                'workflow_node_id' => isset($state->meta['current_node_id'])
                    ? (string) $state->meta['current_node_id']
                    : null,
            ],
            $actionContext,
        );

        if (! $vocab->success) {
            throw new \InvalidArgumentException((string) ($vocab->error['message'] ?? 'Vocabulary save failed.'));
        }

        $ki = is_array($vocab->output['ki_feedback'] ?? null) ? $vocab->output['ki_feedback'] : [];

        return [
            'parent_id' => (int) ($vocab->output['parent_id'] ?? 0),
            'parent_phrase' => (string) ($vocab->output['parent_phrase'] ?? $focusPhrase ?? ''),
            'children_count' => (int) ($vocab->output['children_count'] ?? 0),
            'suggest_count' => (int) ($vocab->output['suggest_count'] ?? 0),
            'tags_count' => (int) ($vocab->output['tags_count'] ?? 0),
            'ki_feedback' => $ki,
        ];
    }

    /**
     * @return list<string>
     */
    public function persistWorkflowMeta(SeoArticle $article, WorkflowExecutionState $state): array
    {
        $savedKeys = [];

        if (isset($state->meta['seo_article_outlines']) && is_array($state->meta['seo_article_outlines'])) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_article_outlines'],
                ['meta_value' => json_encode($state->meta['seo_article_outlines'], JSON_UNESCAPED_UNICODE)],
            );
            $savedKeys[] = 'seo_article_outlines';
        }

        if (isset($state->meta['seo_article_keywords']) && is_array($state->meta['seo_article_keywords'])) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_article_keywords'],
                ['meta_value' => json_encode($state->meta['seo_article_keywords'], JSON_UNESCAPED_UNICODE)],
            );
            $savedKeys[] = 'seo_article_keywords';
        }

        $outlineMarkdown = trim((string) ($state->meta['direct_publish_outline_markdown'] ?? ''));
        if ($outlineMarkdown !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_article_outline'],
                ['meta_value' => $outlineMarkdown],
            );
            $savedKeys[] = 'seo_article_outline';
        }

        if (
            filled($state->lastPromptOutput)
            && $outlineMarkdown === ''
            && trim((string) ($state->meta['direct_publish_article_markdown'] ?? '')) === ''
            && ! ($state->meta['article_markdown_published'] ?? false)
            && ! $this->shouldPublishMarkdownAsArticle(trim((string) $state->lastPromptOutput))
        ) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_article_outline'],
                ['meta_value' => $state->lastPromptOutput],
            );
            $savedKeys[] = 'seo_article_outline';
        }

        if (
            isset($state->meta['seo_article_faqs'])
            && is_array($state->meta['seo_article_faqs'])
            && $state->meta['seo_article_faqs'] !== []
        ) {
            $faqCount = $this->faqPersistence->persistForArticle(
                $article,
                $state->meta['seo_article_faqs'],
            );

            if ($faqCount > 0) {
                $savedKeys[] = 'seo_faqs';
                $this->applyFaqStrippedArticleContent($article, $state);
            }
        }

        $scoreLabel = $this->applyWorkflowSeoScoreToArticle($article, $state);
        if ($scoreLabel !== null) {
            $savedKeys[] = $scoreLabel;
        }

        // Safety net: nếu publishArticle đã chạy thì seo_meta_description đã lưu.
        // Nếu chưa (vd bước Action bị skip), extract từ article markdown và lưu.
        $articleMarkdownForMeta = trim((string) ($state->meta['direct_publish_article_markdown'] ?? ''));
        if ($articleMarkdownForMeta !== '' && ! ($state->meta['article_markdown_published'] ?? false)) {
            $this->persistMetaDescriptionFromMarkdown($article, $articleMarkdownForMeta);
        }

        return $savedKeys;
    }

    /**
     * When content prompt produced article_content but save_article was skipped (partial rerun),
     * flush typed artifact to the article body so editor matches latest AI output.
     */
    private function flushPendingArticleContentIfNeeded(WorkflowExecutionState $state, TaskTestContext $context): void
    {
        if ((bool) ($state->meta['article_markdown_published'] ?? false)) {
            return;
        }

        $article = $state->article ?? $context->article;
        if (! $article instanceof SeoArticle) {
            return;
        }

        $contentArtifact = $this->resolveTypedArtifact($state, WorkflowArtifactType::ArticleContent);
        $markdown = $contentArtifact instanceof WorkflowTypedArtifact
            ? trim($contentArtifact->payload)
            : trim((string) ($state->meta['direct_publish_article_markdown'] ?? ''));

        if ($markdown === '' || $this->artifactReusePolicy->looksLikeOutlineMarkerPayload($markdown)) {
            return;
        }

        if (! $this->artifactReusePolicy->isValidArticleContentPayload($markdown)) {
            return;
        }

        try {
            $publish = $this->promptPublisher->publishArticle(
                $article,
                $markdown,
                $context->variables,
            );
        } catch (\Throwable) {
            return;
        }

        if (! ($publish['success'] ?? false)) {
            return;
        }

        $article = $article->fresh() ?? $article;
        $state->article = $article;
        $state->meta['article_markdown_published'] = true;
        $this->persistWorkflowMeta($article, $state);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function shouldRegisterArticleContentFromPrompt(array $node, string $hookKey): bool
    {
        if ($this->shouldMergeOutlineToSave($node)) {
            return true;
        }

        $hookKey = trim($hookKey);
        if ($hookKey !== '' && in_array($hookKey, [
            ArticleWritingExecutionService::HOOK_KEY,
            'article.content.rewrite',
            'article.content.improve',
        ], true)) {
            return true;
        }

        return $this->isContentRoleNode($node);
    }

    private function persistMetaDescriptionFromMarkdown(SeoArticle $article, string $markdown): void
    {
        $prepared = app(ArticleMarkdownToHtmlService::class)->prepareImport($markdown);
        $metaDescription = trim((string) ($prepared['meta_description'] ?? ''));
        if ($metaDescription === '') {
            return;
        }

        foreach (['seo_meta_description', 'meta_description'] as $key) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $key],
                ['meta_value' => $metaDescription],
            );
        }
    }

    private function applyFaqStrippedArticleContent(SeoArticle $article, WorkflowExecutionState $state): void
    {
        $markdown = trim((string) ($state->lastPromptOutput ?? ''));

        if ($markdown === '') {
            return;
        }

        app(ArticleContentFaqService::class)->applyStrippedContentToArticle($article, $markdown);
    }

    private function refreshWorkflowSeoScore(WorkflowExecutionState $state, string $markdown): void
    {
        $faqs = $state->meta['seo_article_faqs'] ?? [];
        if (! is_array($faqs)) {
            $faqs = [];
        }

        $state->setSeoScoreData($this->workflowParser->calculateSeoScoreFromContent($markdown, $faqs));
    }

    /**
     * @return array<string, mixed>
     */
    private function filterStepResponse(
        string $nodeId,
        string $title,
        string $filterType,
        string $message,
        mixed $parsed,
        string $jsonOutput,
        WorkflowExecutionState $state,
    ): array {
        $response = [
            'node_id' => $nodeId,
            'type' => 'filter',
            'title' => $title,
            'filter_type' => $filterType,
            'status' => 'completed',
            'message' => $message,
            'parsed' => $parsed,
            'output' => $jsonOutput,
        ];

        if (isset($state->meta['seo_score_data']) && is_array($state->meta['seo_score_data'])) {
            $response['seo_score'] = $state->meta['seo_score_data'];
        }

        return $response;
    }

    private function applyWorkflowSeoScoreToArticle(SeoArticle $article, WorkflowExecutionState $state): ?string
    {
        $scoreData = $state->meta['seo_score_data'] ?? null;
        if (! is_array($scoreData)) {
            return null;
        }

        $workflowViolations = is_array($scoreData['violations'] ?? null)
            ? SeoScoringRulesRegistry::sanitizeViolations($scoreData['violations'])
            : [];

        if ($workflowViolations === []) {
            return 'seo_rule_violations (no workflow violations)';
        }

        if (! $article->countsTowardSeoScore()) {
            return 'seo_rule_violations (skipped score)';
        }

        $article->loadMissing('articleMetas');
        $existing = SeoRuleViolationsResolver::forArticle($article);
        $merged = SeoScoringRulesRegistry::sanitizeViolations(array_merge($existing, $workflowViolations));
        $score = SeoScoringCalculator::scoreFromViolations($merged);

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => SeoScoringRulesRegistry::META_KEY_VIOLATIONS],
            ['meta_value' => json_encode($merged, JSON_UNESCAPED_UNICODE)],
        );

        $article->update(['seo_score' => $score]);

        return 'seo_rule_violations (merged '.count($workflowViolations).')';
    }

    private function isArticlePersistAction(string $actionType): bool
    {
        return in_array($actionType, ['save_article', 'create_article', 'edit_article'], true);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function shouldMergeOutlineToSave(array $node): bool
    {
        if (! (bool) ($node['data']['mergeOutlineToSave'] ?? false)) {
            return false;
        }

        $promptId = $node['data']['promptId'] ?? null;
        $prompt = $this->resolvePrompt($promptId);

        return $prompt !== null && $this->promptSupportsMergeOutlineSave($prompt);
    }

    /**
     * Outline role / hook: ghi output vào state để persist seo_article_outline.
     * Không đoán theo title Prompt.
     *
     * @param  array<string, mixed>  $node
     */
    private function captureOutlinePromptOutput(
        array $node,
        SeoPrompt $prompt,
        string $output,
        WorkflowExecutionState $state,
    ): string {
        if ($this->shouldMergeOutlineToSave($node)) {
            return '';
        }

        if (! $this->isOutlineExecutionNode($node, $prompt)) {
            return '';
        }

        // Prefer raw artifact (markers preserved). Fallback cleaned chỉ khi output không đúng contract.
        $raw = trim($output);
        if ($raw === '') {
            return '';
        }

        $stored = $this->articleGenerationInput->isValidArtifact($raw)
            ? $raw
            : $this->cleanWorkflowOutlineMarkdown($raw);
        if ($stored === '') {
            return '';
        }

        $state->meta['direct_publish_outline_markdown'] = $stored;
        $outputs = $state->nodeOutputs[(string) ($node['id'] ?? '')] ?? [];
        if (is_array($outputs)) {
            $outputs['out_outline'] = $stored;
            $state->nodeOutputs[(string) ($node['id'] ?? '')] = $outputs;
        }

        $this->registerTypedArtifact($state, new WorkflowTypedArtifact(
            artifactType: WorkflowArtifactType::ArticleOutline,
            payload: $stored,
            projectId: isset($state->meta['project_id']) ? (int) $state->meta['project_id'] : null,
            projectTaskId: isset($state->meta['project_task_id']) ? (int) $state->meta['project_task_id'] : null,
            articleId: $state->article?->id !== null ? (int) $state->article->id : null,
            runId: isset($state->meta['run_id']) ? (int) $state->meta['run_id'] : null,
            runItemId: isset($state->meta['run_item_id']) ? (int) $state->meta['run_item_id'] : null,
            attempt: isset($state->meta['attempt']) ? (int) $state->meta['attempt'] : null,
            workflowNodeId: (string) ($node['id'] ?? ''),
            producerHookKey: ArticleGenerationInputResolver::OUTLINE_HOOK_KEY,
            workflowGraphVersion: isset($state->meta['workflow_graph_version'])
                ? (string) $state->meta['workflow_graph_version']
                : null,
            inputFingerprint: isset($state->meta['input_fingerprint'])
                ? (string) $state->meta['input_fingerprint']
                : null,
            createdAt: now()->toIso8601String(),
        ));

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function isOutlineRoleNode(array $node, ?string $promptHookKey = null): bool
    {
        if ($promptHookKey === ArticleGenerationInputResolver::OUTLINE_HOOK_KEY) {
            return true;
        }

        if ($promptHookKey === ArticleOutlineVocabularySplitExecutor::OUTLINE_STRUCTURE_HOOK) {
            return true;
        }

        $role = WorkflowExecutionRole::tryFromMixed($node['data']['execution_role'] ?? null);
        if ($role === WorkflowExecutionRole::ArticleOutlineGenerate) {
            return true;
        }

        $title = mb_strtolower(trim((string) ($node['title'] ?? $node['data']['label'] ?? '')));
        if ($title !== '' && (str_contains($title, 'dàn ý') || str_contains($title, 'outline'))) {
            return true;
        }

        $hookKey = trim((string) ($node['data']['hook_key'] ?? ''));

        return $hookKey === ArticleGenerationInputResolver::OUTLINE_HOOK_KEY;
    }

    /**
     * Downstream writing steps must not run (or fail with missing-outline) after outline fail.
     *
     * @param  array<string, mixed>  $node
     */
    private function shouldSkipAfterOutlineFailure(array $node): bool
    {
        if ($this->isContentRoleNode($node)) {
            return true;
        }

        $type = (string) ($node['type'] ?? '');
        if ($type === 'action') {
            $actionType = (string) ($node['data']['actionType'] ?? 'save_article');

            return $this->isArticlePersistAction($actionType);
        }

        return false;
    }

    /**
     * After mandatory content fails: block article persist — never fallback to outline output.
     *
     * @param  array<string, mixed>  $node
     */
    private function shouldBlockAfterContentFailure(array $node): bool
    {
        $type = (string) ($node['type'] ?? '');
        if ($type !== 'action') {
            return false;
        }

        $actionType = (string) ($node['data']['actionType'] ?? 'save_article');

        return $this->isArticlePersistAction($actionType);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function isContentRoleNode(array $node): bool
    {
        $role = WorkflowExecutionRole::tryFromMixed($node['data']['execution_role'] ?? null);
        if ($role === WorkflowExecutionRole::ArticleContentGenerate
            || $role === WorkflowExecutionRole::ArticleContentImprove
        ) {
            return true;
        }

        $title = mb_strtolower(trim((string) ($node['title'] ?? $node['data']['label'] ?? '')));
        if ($title !== '' && (
            str_contains($title, 'viết bài')
            || str_contains($title, 'write')
            || str_contains($title, 'theo dàn ý')
        )) {
            return true;
        }

        $hookKey = trim((string) ($node['data']['hook_key'] ?? ''));

        return in_array($hookKey, [
            'article.content.generate',
            'article.content.rewrite',
            'article.content.improve',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function registerArticleContentFromPromptOutput(
        array $node,
        SeoPrompt $prompt,
        string $output,
        WorkflowExecutionState $state,
        TaskTestContext $context,
        string $hookKey,
    ): void {
        if ($output === '' || $this->artifactReusePolicy->looksLikeOutlineMarkerPayload($output)) {
            // Outline marker payload must never become article_content.
            return;
        }

        if (! $this->artifactReusePolicy->isValidArticleContentPayload($output)) {
            return;
        }

        $state->meta['direct_publish_article_markdown'] = $output;
        $this->registerTypedArtifact($state, new WorkflowTypedArtifact(
            artifactType: WorkflowArtifactType::ArticleContent,
            payload: $output,
            projectId: isset($context->variables['project_id']) ? (int) $context->variables['project_id'] : null,
            projectTaskId: isset($context->variables['project_task_id'])
                ? (int) $context->variables['project_task_id']
                : (isset($context->variables['task_id']) ? (int) $context->variables['task_id'] : null),
            articleId: ($state->article ?? $context->article)?->id !== null
                ? (int) ($state->article ?? $context->article)->id
                : null,
            runId: isset($context->variables['run_id']) ? (int) $context->variables['run_id'] : null,
            runItemId: isset($context->variables['run_item_id']) ? (int) $context->variables['run_item_id'] : null,
            attempt: isset($context->variables['attempt']) ? (int) $context->variables['attempt'] : null,
            workflowNodeId: (string) ($node['id'] ?? ''),
            producerHookKey: $hookKey !== '' ? $hookKey : $this->promptHookKey($prompt),
            workflowGraphVersion: isset($context->variables['workflow_graph_version'])
                ? (string) $context->variables['workflow_graph_version']
                : (isset($context->variables['flow_data_hash']) ? (string) $context->variables['flow_data_hash'] : null),
            inputFingerprint: isset($context->variables['input_fingerprint'])
                ? (string) $context->variables['input_fingerprint']
                : $this->artifactReusePolicy->inputFingerprint(
                    is_array($context->variables) ? $context->variables : [],
                ),
            createdAt: now()->toIso8601String(),
        ));
    }

    private function registerTypedArtifact(WorkflowExecutionState $state, WorkflowTypedArtifact $artifact): void
    {
        $bag = is_array($state->meta['typed_artifacts'] ?? null) ? $state->meta['typed_artifacts'] : [];
        $bag[$artifact->artifactType->value] = $artifact->toArray();
        $state->meta['typed_artifacts'] = $bag;
    }

    private function resolveTypedArtifact(
        WorkflowExecutionState $state,
        WorkflowArtifactType $type,
    ): ?WorkflowTypedArtifact {
        $bag = is_array($state->meta['typed_artifacts'] ?? null) ? $state->meta['typed_artifacts'] : [];
        $raw = is_array($bag[$type->value] ?? null) ? $bag[$type->value] : null;
        if ($raw === null) {
            return null;
        }

        return WorkflowTypedArtifact::tryFromArray($raw);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function isOutlineExecutionNode(array $node, SeoPrompt $prompt): bool
    {
        $role = WorkflowExecutionRole::tryFromMixed($node['data']['execution_role'] ?? null);
        if ($role === WorkflowExecutionRole::ArticleOutlineGenerate) {
            return true;
        }
        if (
            $role === WorkflowExecutionRole::ArticleContentGenerate
            || $role === WorkflowExecutionRole::ArticleContentImprove
        ) {
            return false;
        }

        if ($this->existingAiOutput->outputType($node, $prompt) === WorkflowExistingAiOutputService::TYPE_OUTLINE) {
            return true;
        }

        try {
            $binding = PromptHookBinding::tryFromPrompt($prompt);
            $hookKey = trim((string) ($binding?->hookKey ?? ''));

            return $hookKey === ArticleGenerationInputResolver::OUTLINE_HOOK_KEY;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function isArticleContentGenerationPrompt(array $node, SeoPrompt $prompt): bool
    {
        $role = WorkflowExecutionRole::tryFromMixed($node['data']['execution_role'] ?? null);
        if ($role === WorkflowExecutionRole::ArticleContentGenerate) {
            return true;
        }
        if ($role === WorkflowExecutionRole::ArticleOutlineGenerate) {
            return false;
        }

        if ($this->existingAiOutput->outputType($node, $prompt) === WorkflowExistingAiOutputService::TYPE_CONTENT) {
            return true;
        }

        if ($this->shouldMergeOutlineToSave($node)) {
            return true;
        }

        try {
            $binding = PromptHookBinding::tryFromPrompt($prompt);
            $hookKey = trim((string) ($binding?->hookKey ?? ''));
            if (str_starts_with($hookKey, 'article.content.')) {
                return true;
            }
        } catch (\InvalidArgumentException) {
            // ignore — legacy prompt without hook binding
        }

        return false;
    }

    /**
     * Resolve raw outline artifact cho source=outline (edge / meta / vars / article).
     * Không format — ArticleWritingAssembler lo phần format.
     *
     * @param  list<array<string, mixed>>  $edges
     * @param  array<string, mixed>  $variables
     */
    private function resolveOutlineArtifactForPrompt(
        string $targetNodeId,
        array $edges,
        WorkflowExecutionState $state,
        TaskTestContext $context,
        array $variables,
    ): ?ArticleGenerationSourceResult {
        $sourceType = ArticleWritingSourceType::tryFromMixed(
            $variables['article_writing_source_type'] ?? $variables['source_type'] ?? null,
        );

        // existing_article / brief: không resolve outline, không fallback body→outline.
        if ($sourceType === ArticleWritingSourceType::ExistingArticle
            || $sourceType === ArticleWritingSourceType::Brief
        ) {
            return null;
        }

        $rawPrefill = trim((string) ($variables['article_writing_raw_input'] ?? ''));
        if ($rawPrefill !== '' && ! $this->shouldBlockInheritedGenerationArtifacts($context, $variables)) {
            $fromPrefill = $this->articleGenerationInput->tryResolveFromRawArtifact(
                $rawPrefill,
                (string) ($variables['article_generation_source'] ?? ArticleGenerationSourceResult::SOURCE_RAW_ARTIFACT),
                isset($variables['source_run_id']) ? (int) $variables['source_run_id'] : null,
                isset($variables['source_run_item_id']) ? (int) $variables['source_run_item_id'] : null,
                isset($variables['source_prompt_result_id']) ? (int) $variables['source_prompt_result_id'] : null,
            );
            if ($fromPrefill instanceof ArticleGenerationSourceResult) {
                $state->meta['direct_publish_outline_markdown'] = $fromPrefill->rawArtifact;

                return $fromPrefill;
            }
        }

        return $this->resolveArticleGenerationInputForPrompt(
            $targetNodeId,
            $edges,
            $state,
            $context,
            $variables,
        );
    }

    /**
     * Cùng resolver path first-run: edge outline artifact → state meta → context variables → article lookup.
     *
     * @param  list<array<string, mixed>>  $edges
     * @param  array<string, mixed>  $variables
     */
    private function resolveArticleGenerationInputForPrompt(
        string $targetNodeId,
        array $edges,
        WorkflowExecutionState $state,
        TaskTestContext $context,
        array $variables,
    ): ?ArticleGenerationSourceResult {
        foreach ($edges as $edge) {
            if (! is_array($edge) || (string) ($edge['targetNode'] ?? '') !== $targetNodeId) {
                continue;
            }
            $sourceNodeId = (string) ($edge['sourceNode'] ?? '');
            $sourcePort = (string) ($edge['sourcePort'] ?? 'out_main');
            $value = $this->resolvePortOutput($state, $sourceNodeId, $sourcePort);
            $fromEdge = $this->articleGenerationInput->tryResolveFromRawArtifact(
                $value,
                ArticleGenerationSourceResult::SOURCE_WORKFLOW_EDGE,
            );
            if ($fromEdge instanceof ArticleGenerationSourceResult) {
                $state->meta['direct_publish_outline_markdown'] = $fromEdge->rawArtifact;

                return $fromEdge;
            }
        }

        $fromMeta = $this->articleGenerationInput->tryResolveFromRawArtifact(
            trim((string) ($state->meta['direct_publish_outline_markdown'] ?? '')),
            ArticleGenerationSourceResult::SOURCE_STATE_META,
        );
        if ($fromMeta instanceof ArticleGenerationSourceResult) {
            return $fromMeta;
        }

        $fromVars = $this->articleGenerationInput->tryResolveFromRawArtifact(
            trim((string) ($variables['input'] ?? '')),
            (string) ($variables['article_generation_source'] ?? ArticleGenerationSourceResult::SOURCE_RAW_ARTIFACT),
            isset($variables['source_run_id']) ? (int) $variables['source_run_id'] : null,
            isset($variables['source_run_item_id']) ? (int) $variables['source_run_item_id'] : null,
            isset($variables['source_prompt_result_id']) ? (int) $variables['source_prompt_result_id'] : null,
        );
        if ($fromVars instanceof ArticleGenerationSourceResult
            && ! $this->shouldBlockInheritedGenerationArtifacts($context, $variables)
        ) {
            $state->meta['direct_publish_outline_markdown'] = $fromVars->rawArtifact;

            return $fromVars;
        }

        $article = $state->article ?? $context->article;
        if ($article instanceof SeoArticle && ! $this->shouldBlockInheritedGenerationArtifacts($context, $variables)) {
            try {
                $fromArticle = $this->articleGenerationInput->resolveForArticle($article);

                $state->meta['direct_publish_outline_markdown'] = $fromArticle->rawArtifact;

                return $fromArticle;
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nodeExecutionRoleValue(array $node): ?string
    {
        return WorkflowExecutionRole::tryFromMixed($node['data']['execution_role'] ?? null)?->value;
    }

    private function promptHookKey(SeoPrompt $prompt): ?string
    {
        try {
            $binding = PromptHookBinding::tryFromPrompt($prompt);
            $hook = trim((string) ($binding?->hookKey ?? ''));

            return $hook !== '' ? $hook : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function promptSupportsMergeOutlineSave(SeoPrompt $prompt): bool
    {
        try {
            $binding = PromptHookBinding::tryFromPrompt($prompt);
            $hook = trim((string) ($binding?->hookKey ?? ''));

            return $hook === ArticleWritingExecutionService::HOOK_KEY
                || $hook === ArticleWritingLegacyRewriteAdapter::LEGACY_REWRITE_HOOK
                || $hook === 'article.content.generate';
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @deprecated Removed from domain write path — article body must come from typed article_content only.
     * Kept for source/contract tests that assert the heuristic is no longer used for persist fallback.
     */
    private function shouldPublishMarkdownAsArticle(string $markdown): bool
    {
        // Fail-closed: never treat arbitrary/latest prompt output as article body.
        if ($this->artifactReusePolicy->looksLikeOutlineMarkerPayload($markdown)) {
            return false;
        }

        return false;
    }

    private function cleanWorkflowOutlineMarkdown(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }

        $input = (string) preg_replace('/^\s*\[(?:START|END)_[A-Z0-9_]+\]\s*$/mu', '', $input);
        $input = (string) preg_replace("/\n{3,}/u", "\n\n", $input);

        return trim($input);
    }

    private function createArticleFromContext(TaskTestContext $context): ?SeoArticle
    {
        $siteId = $this->resolveSiteIdForNewArticle($context);
        if ($siteId === null) {
            return null;
        }

        $variables = $context->variables;
        $title = trim((string) ($variables['post_title'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($variables['focus_keyword'] ?? 'Bài viết mới'));
        }

        $slugSource = trim((string) ($variables['focus_keyword'] ?? ''));
        if ($slugSource === '') {
            $slugSource = $title;
        }
        $slug = \Illuminate\Support\Str::slug($slugSource);

        $postType = SeoProjectTask::normalizePostType(
            (string) ($context->postType ?? $variables['_project_post_type'] ?? 'article'),
        );
        $scheduleAt = $this->shouldScheduleProjectArticle($context)
            ? now(config('app.timezone'))->addDay()->startOfDay()
            : null;

        $article = SeoArticle::query()->create([
            'site_id' => $siteId,
            'user_id' => auth()->id(),
            'type' => $postType,
            'title' => $title,
            'slug' => $slug !== '' ? $slug : null,
            'status' => $scheduleAt !== null ? 'scheduled' : 'draft',
            'published_at' => $scheduleAt,
            'body' => '',
            'language' => 'vi',
        ]);

        $article->articleMetas()->where('meta_key', 'wp_post_type')->delete();

        $this->persistProductPromptMeta($article, $variables);

        $focusKeyword = trim((string) ($variables['focus_keyword'] ?? ''));
        if ($focusKeyword !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_focus_keyword'],
                ['meta_value' => $focusKeyword],
            );

            KeywordFocusAttach::attachMainKeyword(
                $article,
                $siteId,
                $focusKeyword,
            );
        }

        // Gắn ngay vào Content Project item để UI có link click được (prompt history).
        $originId = ProjectTaskOriginVariables::read($variables);
        if ($originId === null) {
            $fallbackTaskId = (int) ($variables['project_task_id'] ?? $variables['task_id'] ?? 0);
            $originId = $fallbackTaskId > 0 ? $fallbackTaskId : null;
        }
        if ($originId !== null) {
            $originResolver = app(\Omnichannel\Addons\Agent\Automation\Support\ArticleCreateOriginResolver::class);
            $originResolver->persistOriginMeta(
                $article,
                \Omnichannel\Addons\Agent\Automation\Support\ArticleCreateOriginResolver::ORIGIN_SEO_PROJECT_TASK,
                $originId,
            );
            $originResolver->attachToProjectTaskIfNeeded($originId, (int) $article->id);
        }

        return $article;
    }

    private function shouldScheduleProjectArticle(TaskTestContext $context): bool
    {
        return SeoProjectTask::isNewArticleType($context->projectTaskType);
    }

    private function shouldRunProductGalleryPrompt(TaskTestContext $context, WorkflowExecutionState $state): bool
    {
        $article = $this->resolveArticleForProductAlbumCheck($context, $state);
        if (! $article instanceof SeoArticle) {
            return true;
        }

        $article->unsetRelation('articleMetas');

        // Mode 1: skip only when gallery already ready (AI children or original fallback).
        if (\Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::isReadyOnArticle($article)) {
            return false;
        }

        return true;
    }

    private function shouldReuseExistingAiOutput(TaskTestContext $context): bool
    {
        // Explicit Content Project step rerun («Rerun from Writing/Outline») must call AI.
        $force = strtolower(trim((string) ($context->variables['force_ai_regenerate'] ?? '')));
        if (in_array($force, ['1', 'true', 'yes'], true)) {
            return false;
        }
        $rerunFromStep = strtolower(trim((string) ($context->variables['rerun_from_step'] ?? '')));
        if (in_array($rerunFromStep, ['article', 'outline'], true)) {
            return false;
        }
        $rerunScope = strtolower(trim((string) ($context->variables['rerun_scope'] ?? '')));
        if ($rerunScope === 'full') {
            return false;
        }

        // Viết lại / có rewriteMode → luôn gọi AI lại (tránh OK giả vì reuse body/dàn ý cũ).
        if ($context->projectTaskType === SeoProjectTask::TYPE_REWRITE
            || $context->projectTaskType === SeoProjectTask::TYPE_IMPROVE
        ) {
            return false;
        }

        if ($context->rewriteMode !== null && trim((string) $context->rewriteMode) !== '') {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function shouldBlockInheritedGenerationArtifacts(TaskTestContext $context, array $variables): bool
    {
        if (\Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFreshKeywordRestart::isActive($variables)) {
            return true;
        }

        if (! \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFreshKeywordRestart::shouldInheritPreviousGeneration($variables)) {
            return true;
        }

        if (! \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFreshKeywordRestart::shouldUseExistingOutline($variables)) {
            return true;
        }

        return false;
    }

    private function isProductWorkflowContext(TaskTestContext $context, WorkflowExecutionState $state): bool
    {
        $postType = SeoProjectTask::normalizePostType((string) ($context->postType ?? ''));
        if ($postType === SeoProjectTask::POST_TYPE_PRODUCT) {
            return true;
        }

        // Context đã gắn postType (vd. article) → không suy luận lại từ article.type lệch.
        if (trim((string) ($context->postType ?? '')) !== '') {
            return false;
        }

        $article = $this->resolveArticleForProductAlbumCheck($context, $state);
        if (! $article instanceof SeoArticle) {
            return false;
        }

        return ArticlePostTypeResolver::resolve($article) === SeoProjectTask::POST_TYPE_PRODUCT;
    }

    private function wpPostTypeMetaValue(string $postType): string
    {
        return match (SeoProjectTask::normalizePostType($postType)) {
            SeoProjectTask::POST_TYPE_PRODUCT => 'product',
            SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY => 'product_cat',
            SeoProjectTask::POST_TYPE_CATEGORY => 'category',
            default => 'post',
        };
    }

    private function resolveArticleForProductAlbumCheck(TaskTestContext $context, WorkflowExecutionState $state): ?SeoArticle
    {
        $article = $this->resolveWorkflowArticle($context, $state);
        if ($article instanceof SeoArticle) {
            return $article;
        }

        $articleId = (int) ($context->variables['article_id'] ?? 0);
        if ($articleId > 0) {
            $byId = SeoArticle::query()->find($articleId);
            if ($byId instanceof SeoArticle) {
                return $byId;
            }
        }

        $siteId = (int) ($context->siteId ?? $context->article?->site_id ?? 0);
        $keyword = trim((string) ($context->variables['focus_keyword'] ?? ''));
        if ($keyword === '') {
            $keyword = trim((string) ($context->variables['post_title'] ?? ''));
        }

        if ($siteId <= 0 || $keyword === '') {
            return null;
        }

        $baseQuery = SeoArticle::query()->where('site_id', $siteId);

        $byTitle = (clone $baseQuery)
            ->where('title', $keyword)
            ->orderByDesc('id')
            ->first();

        if ($byTitle instanceof SeoArticle) {
            return $byTitle;
        }

        $byTitleLike = (clone $baseQuery)
            ->where('title', 'like', '%'.$this->escapeLike($keyword).'%')
            ->orderByDesc('id')
            ->first();

        if ($byTitleLike instanceof SeoArticle) {
            return $byTitleLike;
        }

        $normalized = mb_strtolower($keyword);

        return (clone $baseQuery)
            ->whereHas('articleMetas', static function ($query) use ($normalized, $keyword): void {
                $query->where('meta_key', 'seo_focus_keyword')
                    ->where(function ($inner) use ($normalized, $keyword): void {
                        $inner->whereRaw('LOWER(meta_value) = ?', [$normalized])
                            ->orWhere('meta_value', 'like', '%'.addcslashes($keyword, '%_\\').'%');
                    });
            })
            ->orderByDesc('id')
            ->first();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }

    private function resolveWorkflowArticle(TaskTestContext $context, WorkflowExecutionState $state): ?SeoArticle
    {
        return $state->article ?? $context->article;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<array<string, mixed>>  $edges
     */
    private function isProductGalleryPromptNode(string $nodeId, SeoPrompt $prompt, array $edges): bool
    {
        if (! \Omnichannel\Addons\Media\Support\ImageToolType::fromMixed($prompt->tools ?? 'default')->isImagePipeline()) {
            return false;
        }

        $configuredPromptId = $this->createArticleSettings->getCreateProductGalleryImagePromptId();
        if ($configuredPromptId !== null && (int) $prompt->id === $configuredPromptId) {
            return true;
        }

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            if ((string) ($edge['targetNode'] ?? '') !== $nodeId) {
                continue;
            }

            if ((string) ($edge['sourcePort'] ?? '') === 'out_gallery_description') {
                return true;
            }
        }

        $detectedTags = is_array($prompt->settings['detected_tags'] ?? null)
            ? $prompt->settings['detected_tags']
            : [];

        foreach ($detectedTags as $tag) {
            if (strtolower(trim((string) $tag)) === 'gallery_description') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function persistProductPromptMeta(SeoArticle $article, array $variables): void
    {
        $galleryDescription = trim((string) ($variables['gallery_description'] ?? ''));
        if ($galleryDescription !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'gallery_description'],
                ['meta_value' => $galleryDescription],
            );
        }

        $loaiSanPham = trim((string) ($variables['loai_san_pham'] ?? $variables['LOAI_SAN_PHAM'] ?? ''));
        if ($loaiSanPham !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'loai_san_pham'],
                ['meta_value' => $loaiSanPham],
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     */
    private function persistProductGalleryUrlsFromOutput(SeoArticle $article, string $output): int
    {
        // Mode 1 pipeline owns album when gallery_ready already set.
        if (\Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::isReadyOnArticle($article)) {
            return 0;
        }

        $added = 0;

        foreach ($this->extractGalleryImageUrls($output) as $url) {
            $media = $this->resolveSeoMediaFromUrl($url);
            if (! $media instanceof SeoMedia) {
                continue;
            }

            // Never auto-insert generated_sprite as gallery when Mode 1 will/did handle fallback.
            $role = \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::artifactRole($media);
            if ($role === \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::ROLE_GENERATED_SPRITE) {
                continue;
            }

            if (app(ArticleMediaLocalService::class)->appendGeneratedImageToProductAlbum($article, $media, $url)) {
                $added++;
            }
        }

        return $added;
    }

    private function runProductGalleryMode1FromWorkflowOutput(
        SeoArticle $article,
        SeoPrompt $prompt,
        string $output,
    ): void {
        $sprite = $this->resolveSeoMediaFromOutput($output);
        if (! $sprite instanceof SeoMedia) {
            return;
        }

        $this->attachMediaToArticleScope($sprite, $article);

        // Mark as product-gallery sprite so Mode 1 ownership metadata is consistent.
        if (trim((string) ($sprite->editor_block_id ?? '')) === '') {
            $sprite->update([
                'editor_block_id' => ArticleEditorMediaAiService::PRODUCT_GALLERY_EDITOR_BLOCK_ID,
            ]);
            $sprite->refresh();
        }

        $variables = is_array($sprite->prompt_variables) ? $sprite->prompt_variables : [];
        $state = \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::readFromVariables($variables);
        if (($state['fallback_snapshot']['urls'] ?? []) === [] && ($state['fallback_snapshot']['media_ids'] ?? []) === []) {
            $album = app(ArticleMediaLocalService::class)->resolveProductAlbum($article);
            $variables = \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::mergeIntoVariables($variables, [
                'gallery_ready' => false,
                'gallery_source' => \Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySource::Pending->value,
                'fallback_snapshot' => \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::buildFallbackSnapshot(
                    $album,
                    \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::ORIGIN_ALBUM_BEFORE_GENERATE,
                ),
            ]);
            $sprite->update(['prompt_variables' => $variables]);
            $sprite->refresh();
        }

        try {
            app(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPipelineService::class)
                ->runAfterSpriteSaved($sprite, $prompt, $article);
        } catch (\Throwable $exception) {
            logger()->warning(sprintf(
                'Workflow product gallery Mode 1 failed [article_id=%d, media_id=%d]: %s',
                (int) $article->id,
                (int) $sprite->id,
                $exception->getMessage(),
            ));
            // Fallback: keep originals only; never append sprite as gallery.
            $this->persistProductGalleryUrlsFromOutput($article, '');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     */
    private function appendGalleryImagesFromActionEdges(
        SeoArticle $article,
        string $actionNodeId,
        array $edges,
        WorkflowExecutionState $state,
    ): int {
        if ($actionNodeId === '') {
            return 0;
        }

        $added = 0;
        $seen = [];

        foreach ($edges as $edge) {
            if (! is_array($edge) || (string) ($edge['targetNode'] ?? '') !== $actionNodeId) {
                continue;
            }

            $sourceNodeId = (string) ($edge['sourceNode'] ?? '');
            $sourcePort = (string) ($edge['sourcePort'] ?? 'out_main');
            $output = $this->resolvePortOutput($state, $sourceNodeId, $sourcePort);
            if ($output === '') {
                continue;
            }

            foreach ($this->extractGalleryImageUrls($output) as $url) {
                if (isset($seen[$url])) {
                    continue;
                }

                $seen[$url] = true;
                $media = $this->resolveSeoMediaFromUrl($url);
                if (! $media instanceof SeoMedia) {
                    continue;
                }

                $this->attachMediaToArticleScope($media, $article);

                $mediaService = app(ArticleMediaLocalService::class);
                $article->unsetRelation('articleMetas');
                $beforeCount = count($mediaService->resolveGallery($article));
                $article->unsetRelation('articleMetas');
                $after = $mediaService->appendGalleryLocal($article, (int) $media->id, $url);
                if (count($after) > $beforeCount) {
                    $added++;
                }
            }
        }

        return $added;
    }

    private function quickFixProductGalleryMedia(
        SeoArticle $article,
        TaskTestContext $context,
        ArticleMediaLocalService $mediaService,
    ): int {
        $keyword = $this->resolveGalleryQuickFixKeyword($article, $context);
        if ($keyword === '') {
            return 0;
        }

        $album = $mediaService->resolveProductAlbum($article);
        if ($album === []) {
            return 0;
        }

        $fixedCount = 0;
        $nextAlbum = [];
        foreach ($album as $index => $item) {
            $url = trim((string) ($item['url'] ?? ''));
            $media = $this->resolveSeoMediaFromGalleryItem($item, $url);

            if ($media instanceof SeoMedia) {
                $media->alt_text = $keyword;
                $media->save();
                $fixedCount++;

                $targetSlug = Str::slug($keyword.'-'.($index + 1));
                if ($targetSlug !== '' && (string) ($media->slug ?? '') !== $targetSlug) {
                    try {
                        $media = $this->mediaStorage->renameBySlug($media, $targetSlug);
                    } catch (\Throwable $exception) {
                        logger()->warning('Workflow quick fix gallery media slug failed', [
                            'article_id' => (int) $article->id,
                            'media_id' => (int) $media->id,
                            'slug' => $targetSlug,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }

                $url = $media->publicUrl();
                $item['id'] = (int) $media->id;
                $item['url'] = $url;
            }

            $nextAlbum[] = [
                'id' => max(0, (int) ($item['id'] ?? 0)),
                'url' => $url,
            ];
        }

        if ($fixedCount > 0) {
            $mediaService->saveProductAlbumLocal($article, $nextAlbum);
        }

        return $fixedCount;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveSeoMediaFromGalleryItem(array $item, string $url): ?SeoMedia
    {
        $mediaId = (int) ($item['id'] ?? 0);
        if ($mediaId > 0) {
            $media = SeoMedia::query()->find($mediaId);
            if ($media instanceof SeoMedia) {
                return $media;
            }
        }

        return $this->resolveSeoMediaFromUrl($url);
    }

    private function resolveGalleryQuickFixKeyword(SeoArticle $article, TaskTestContext $context): string
    {
        $keyword = trim((string) ($context->variables['focus_keyword'] ?? ''));
        if ($keyword !== '') {
            return $keyword;
        }

        $keyword = trim((string) ($article->articleMetas()
            ->where('meta_key', 'seo_focus_keyword')
            ->value('meta_value') ?? ''));
        if ($keyword !== '') {
            return $keyword;
        }

        return trim((string) ($article->title ?? ''));
    }

    private function attachMediaToArticleScope(SeoMedia $media, SeoArticle $article): void
    {
        $updates = [];
        $siteId = (int) ($article->site_id ?? 0);
        $articleId = (int) ($article->id ?? 0);

        if ($siteId > 0 && (int) ($media->site_id ?? 0) <= 0) {
            $updates['site_id'] = $siteId;
        }

        if ($articleId > 0) {
            $articleIds = SeoMedia::normalizeArticleIds($media->article_id);
            if (! in_array($articleId, $articleIds, true)) {
                $articleIds[] = $articleId;
                $updates['article_id'] = array_values(array_unique($articleIds));
            }
        }

        if (array_key_exists('primary_article_id', $media->getAttributes()) && (int) ($media->primary_article_id ?? 0) <= 0) {
            $updates['primary_article_id'] = $articleId > 0 ? $articleId : null;
        }

        if ($updates !== []) {
            $media->fill($updates)->save();
        }
    }

    private function applyPromptPostProcessing(SeoPrompt $prompt, string $output): string
    {
        $media = $this->resolveSeoMediaFromOutput($output);
        if (! $media instanceof SeoMedia) {
            return $output;
        }

        try {
            $variables = is_array($media->prompt_variables) ? $media->prompt_variables : [];
            if (PromptPostProcessing::fromVariablesSnapshot($variables) === null) {
                $variables = PromptPostProcessing::attachSnapshotToVariables(
                    $variables,
                    PromptPostProcessing::fromPrompt($prompt),
                );
                $media->update(['prompt_variables' => $variables]);
                $media = $media->fresh() ?? $media;
            }

            $result = app(PromptPostProcessingApplyService::class)->applyIfConfigured($media, $prompt);
        } catch (\Throwable $exception) {
            logger()->warning(sprintf(
                'Workflow prompt post-processing failed [prompt_id=%d, media_id=%d]: %s',
                (int) $prompt->id,
                (int) $media->id,
                $exception->getMessage(),
            ));

            return $output;
        }

        if (! $result->applied || $result->pieces === []) {
            return $output;
        }

        $urls = array_values(array_filter(
            $result->publicUrls(),
            static fn (string $url): bool => trim($url) !== '',
        ));

        return $urls !== [] ? implode("\n", $urls) : $output;
    }

    private function resolveSeoMediaFromOutput(string $output): ?SeoMedia
    {
        foreach ($this->extractGalleryImageUrls($output) as $url) {
            $media = $this->resolveSeoMediaFromUrl($url);
            if ($media instanceof SeoMedia) {
                return $media;
            }
        }

        return null;
    }

    private function resolveMediaContextSiteId(TaskTestContext $context, WorkflowExecutionState $state): ?int
    {
        $siteId = (int) ($state->article?->site_id ?? $context->article?->site_id ?? $context->siteId ?? 0);

        return $siteId > 0 ? $siteId : null;
    }

    /**
     * @return list<string>
     */
    private function extractGalleryImageUrls(string $output): array
    {
        $urls = [];

        $count = preg_match_all('~(?:https?://[^\s<>"\']+|/storage/[^\s<>"\']+)~iu', $output, $matches);
        if ($count === false || $count < 1) {
            return [];
        }

        foreach ($matches[0] as $rawUrl) {
            $url = rtrim(trim((string) $rawUrl), '.,);]');
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($path === '') {
                $path = $url;
            }

            if (preg_match('/\.(?:jpe?g|png|webp|gif)(?:$|\?)/iu', $path) !== 1) {
                continue;
            }

            $urls[] = $url;
        }

        return array_values(array_unique($urls));
    }

    private function resolveSeoMediaFromUrl(string $url): ?SeoMedia
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $relativePath = str_starts_with($path, '/storage/')
            ? ltrim(substr($path, strlen('/storage/')), '/')
            : '';

        return SeoMedia::query()
            ->where(function ($query) use ($url, $path, $relativePath): void {
                $query->where('url', $url);

                if ($path !== '') {
                    $query->orWhere('url', $path);
                }

                if ($relativePath !== '') {
                    $query->orWhere('path', $relativePath);
                }
            })
            ->latest('id')
            ->first();
    }

    private function resolveSiteIdForNewArticle(TaskTestContext $context): ?int
    {
        if ($context->article !== null) {
            return (int) $context->article->site_id;
        }

        if ($context->siteId !== null && $context->siteId > 0) {
            return $context->siteId;
        }

        $query = Site::query()->orderBy('id');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        $siteId = $query->value('id');

        return is_numeric($siteId) ? (int) $siteId : null;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    public function applyParsedMetaFromSteps(SeoArticle $article, array $steps): void
    {
        $state = new WorkflowExecutionState;

        foreach ($steps as $step) {
            $this->applyCompletedStepToState($step, $state);
        }

        $this->persistWorkflowMeta($article, $state);
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     * @return list<array<string, mixed>>
     */
    private function orderedNodes(array $nodes, array $edges): array
    {
        $byId = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = (string) ($node['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $node;
            }
        }

        if ($byId === []) {
            return [];
        }

        $adjacency = [];
        $inDegree = array_fill_keys(array_keys($byId), 0);

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $source = (string) ($edge['sourceNode'] ?? '');
            $target = (string) ($edge['targetNode'] ?? '');
            if ($source === '' || $target === '' || ! isset($byId[$source], $byId[$target])) {
                continue;
            }
            $adjacency[$source][] = $target;
            $inDegree[$target] = ($inDegree[$target] ?? 0) + 1;
        }

        $queue = [];
        foreach ($inDegree as $id => $degree) {
            if ($degree === 0) {
                $queue[] = $id;
            }
        }

        usort($queue, static function (string $left, string $right) use ($byId): int {
            $leftIsArticle = ($byId[$left]['type'] ?? '') === 'article';
            $rightIsArticle = ($byId[$right]['type'] ?? '') === 'article';

            return (int) $rightIsArticle <=> (int) $leftIsArticle;
        });

        if ($queue === []) {
            $queue[] = array_key_first($byId);
        }

        $visited = [];
        $ordered = [];

        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $ordered[] = $byId[$id];

            foreach ($adjacency[$id] ?? [] as $nextId) {
                $inDegree[$nextId] = max(0, (int) ($inDegree[$nextId] ?? 0) - 1);
                if (! isset($visited[$nextId]) && $inDegree[$nextId] === 0) {
                    $queue[] = $nextId;
                }
            }
        }

        foreach ($byId as $id => $node) {
            if (! isset($visited[$id])) {
                $ordered[] = $node;
            }
        }

        return $ordered;
    }

    /**
     * @return array<string, string>
     */
    private function buildPromptNodeOutputs(SeoPrompt $prompt, string $output, WorkflowExecutionState $state): array
    {
        $outputs = ['out_main' => $output];

        $detectedTags = $this->tagExtractor->detectTagsFromPromptTemplate((string) ($prompt->markdown_content ?? ''));
        foreach ($detectedTags as $tag) {
            $tagId = trim((string) ($tag['id'] ?? ''));
            $tagKey = trim((string) ($tag['key'] ?? ''));
            if ($tagId === '' || $tagKey === '') {
                continue;
            }

            $extract = $this->tagExtractor->extractSegment($output, $tagKey);
            $segment = trim((string) ($extract['content'] ?? ''));
            $outputs['out_'.$tagId] = $segment;
            $state->meta['extracted_segments'][$tagKey] = $segment;
        }

        return $outputs;
    }

    /**
     * Map markdown_sections ports onto existing workflow nodeOutputs without new engine.
     *
     * @param  array<string, string>  $ports
     * @param  array<string, string>  $sections
     * @return array<string, string>
     */
    private function buildPromptNodeOutputsFromHook(
        SeoPrompt $prompt,
        string $output,
        array $ports,
        array $sections,
        WorkflowExecutionState $state,
    ): array {
        $outputs = $this->buildPromptNodeOutputs($prompt, $output, $state);

        if ($ports === []) {
            return $outputs;
        }

        $total = trim((string) ($ports['total'] ?? $output));
        if ($total !== '') {
            $outputs['out_main'] = $total;
            $outputs['total'] = $total;
        }

        foreach ($ports as $port => $content) {
            $port = trim((string) $port);
            $content = trim((string) $content);
            if ($port === '' || $port === 'total') {
                continue;
            }
            $outputs[$port] = $content;
            $outputs['out_'.$port] = $content;

            // BC alias: task_1_outline → out_task_1 when downstream still uses Task 1 port id.
            if (preg_match('/^task_(\d+)_/', $port, $matches) === 1) {
                $outputs['out_task_'.$matches[1]] = $content;
            }
        }

        foreach ($sections as $sectionKey => $content) {
            $state->meta['extracted_segments'][(string) $sectionKey] = trim((string) $content);
        }

        return $outputs;
    }

    /**
     * @param  array<int, array<string, mixed>>  $edges
     */
    private function resolveInputForNode(string $targetNodeId, array $edges, WorkflowExecutionState $state): string
    {
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            if ((string) ($edge['targetNode'] ?? '') !== $targetNodeId) {
                continue;
            }

            $sourceNodeId = (string) ($edge['sourceNode'] ?? '');
            $sourcePort = (string) ($edge['sourcePort'] ?? 'out_main');
            $value = $this->resolvePortOutput($state, $sourceNodeId, $sourcePort);

            if ($value !== '') {
                return $value;
            }
        }

        return trim((string) ($state->lastPromptOutput ?? ''));
    }

    private function resolvePortOutput(WorkflowExecutionState $state, string $sourceNodeId, string $sourcePort): string
    {
        if ($sourceNodeId === '') {
            return '';
        }

        $outputs = $state->nodeOutputs[$sourceNodeId] ?? [];

        if ($sourcePort === 'out_main' && ! array_key_exists('out_main', $outputs)) {
            $sourcePort = 'out_keyword';
        }

        if (array_key_exists($sourcePort, $outputs)) {
            return trim((string) $outputs[$sourcePort]);
        }

        if ($sourcePort === 'out_input') {
            return trim((string) ($outputs['out_input'] ?? $outputs['out_main'] ?? ''));
        }

        if (in_array($sourcePort, ['out_description', 'out_gallery_description'], true)) {
            return '';
        }

        return trim((string) ($outputs['out_main'] ?? $outputs['out_keyword'] ?? ''));
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{out_keyword: string, out_gallery_description: string, out_combined: string, out_main: string}
     */
    private function buildArticleFilterNodeOutputs(array $variables): array
    {
        $keywordOrTitle = $this->resolveKeywordOrTitle($variables);
        $galleryDescription = trim((string) ($variables['gallery_description'] ?? ''));
        $combined = trim($keywordOrTitle."\n\n".$galleryDescription);

        return [
            'out_keyword' => $keywordOrTitle,
            'out_gallery_description' => $galleryDescription,
            'out_combined' => $combined,
            'out_main' => $keywordOrTitle,
        ];
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function resolveKeywordOrTitle(array $variables): string
    {
        $keywordOrTitle = trim((string) ($variables['focus_keyword'] ?? ''));
        if ($keywordOrTitle !== '') {
            return $keywordOrTitle;
        }

        return trim((string) ($variables['post_title'] ?? ''));
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function normalizeWorkflowEdges(array $edges, array $nodes): array
    {
        $articleNodeIds = [];
        $articleFilterNodeIds = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $nodeId = (string) ($node['id'] ?? '');
            if ((string) ($node['type'] ?? '') === 'article') {
                $articleNodeIds[$nodeId] = true;
            }

            if ((string) ($node['type'] ?? '') === 'article_filter') {
                $articleFilterNodeIds[$nodeId] = true;
            }
        }

        $normalized = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $sourceNodeId = (string) ($edge['sourceNode'] ?? '');
            $sourcePort = (string) ($edge['sourcePort'] ?? 'out_main');

            if ($sourceNodeId !== '' && isset($articleFilterNodeIds[$sourceNodeId]) && $sourcePort === 'out_main') {
                $edge['sourcePort'] = 'out_keyword';
            }

            if ($sourceNodeId !== '' && isset($articleFilterNodeIds[$sourceNodeId]) && $sourcePort === 'out_description') {
                $edge['sourcePort'] = 'out_gallery_description';
            }

            if ($sourceNodeId !== '' && isset($articleNodeIds[$sourceNodeId])) {
                $filterNodeId = $sourceNodeId.'_article_filter';
                $targetNodeId = (string) ($edge['targetNode'] ?? '');
                $targetIsArticleFilter = isset($articleFilterNodeIds[$targetNodeId]);

                if (
                    isset($articleFilterNodeIds[$filterNodeId])
                    && $targetNodeId !== $filterNodeId
                    && ! $targetIsArticleFilter
                ) {
                    $edge['sourceNode'] = $filterNodeId;
                    $edge['sourcePort'] = $sourcePort === 'out_description'
                        ? 'out_gallery_description'
                        : 'out_keyword';
                } elseif (in_array($sourcePort, ['out_main', 'out_keyword'], true)) {
                    $edge['sourcePort'] = 'out_main';
                }
            }

            $normalized[] = $edge;
        }

        return $normalized;
    }

    private function resolvePrompt(mixed $promptId): ?SeoPrompt
    {
        if ($promptId === null || $promptId === '') {
            return null;
        }

        if (is_numeric($promptId)) {
            return SeoPrompt::query()->find((int) $promptId);
        }

        $idString = (string) $promptId;
        if (preg_match('/^p(\d+)$/', $idString, $matches)) {
            return SeoPrompt::query()->find((int) $matches[1]);
        }

        return SeoPrompt::query()
            ->where('id', $idString)
            ->first();
    }

    private function missingPromptMessage(mixed $promptId): string
    {
        if ($promptId === null || $promptId === '') {
            return 'Widget Prompt chưa chọn prompt. Mở Builder → chọn prompt tạo ảnh cho bước này.';
        }

        $numericId = null;
        if (is_numeric($promptId)) {
            $numericId = (int) $promptId;
        } elseif (preg_match('/^p(\d+)$/', (string) $promptId, $matches)) {
            $numericId = (int) $matches[1];
        }

        if ($numericId === null) {
            return 'Không tìm thấy prompt «'.(string) $promptId.'». Mở Builder → gắn lại prompt cho widget này.';
        }

        $inactive = false;
        if ($inactive) {
            return 'Prompt #'.$numericId.' đang tắt (is_active=0). Bật lại prompt hoặc chọn prompt khác trong Builder.';
        }

        $exists = SeoPrompt::query()->whereKey($numericId)->exists();
        if (! $exists) {
            return 'Prompt #'.$numericId.' không còn trong DB (đã xóa). Mở Builder → chọn lại prompt tạo ảnh cho widget 2.';
        }

        return 'Không resolve được prompt #'.$numericId.'. Mở Builder → gắn lại prompt.';
    }

    /**
     * Prompt node tools=image cuối cùng theo thứ tự topo (editor «Tạo ảnh»).
     */
    public function resolveImagePromptForTask(SeoTask $task): SeoPrompt
    {
        $ordered = $this->orderedNodesForTask($task);
        $imagePrompt = null;

        foreach ($ordered as $node) {
            if ((string) ($node['type'] ?? '') !== 'prompt') {
                continue;
            }

            $prompt = $this->resolvePrompt($node['data']['promptId'] ?? null);
            if ($prompt === null) {
                continue;
            }

            if (! \Omnichannel\Addons\Media\Support\ImageToolType::fromMixed($prompt->tools ?? 'default')->isImagePipeline()) {
                continue;
            }

            $imagePrompt = $prompt;
        }

        if ($imagePrompt === null) {
            throw new \InvalidArgumentException(
                'Quy trình «'.(string) ($task->name ?? '').'» chưa có bước Prompt Hình ảnh (tools=image|image_typography).',
            );
        }

        return $imagePrompt;
    }

    /**
     * Prompt node tools=video cuối cùng theo thứ tự topo (editor «Tạo video» workflow).
     * Phase 1: extract only — chưa execute full workflow graph.
     */
    public function resolveVideoPromptForTask(SeoTask $task): SeoPrompt
    {
        $ordered = $this->orderedNodesForTask($task);
        $videoPrompt = null;

        foreach ($ordered as $node) {
            if ((string) ($node['type'] ?? '') !== 'prompt') {
                continue;
            }

            $prompt = $this->resolvePrompt($node['data']['promptId'] ?? null);
            if ($prompt === null) {
                continue;
            }

            if (\Omnichannel\Addons\Media\Support\ImageToolType::fromMixed($prompt->tools ?? 'default') !== \Omnichannel\Addons\Media\Support\ImageToolType::Video) {
                continue;
            }

            $videoPrompt = $prompt;
        }

        if ($videoPrompt === null) {
            throw new \InvalidArgumentException(
                'Quy trình «'.(string) ($task->name ?? '').'» chưa có bước Prompt Video (tools=video).',
            );
        }

        return $videoPrompt;
    }
}
