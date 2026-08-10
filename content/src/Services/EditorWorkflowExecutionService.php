<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Omnichannel\Addons\AiPrompt\Support\PromptMediaPersistContext;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;

/**
 * Chạy full workflow graph từ Editor — BC extract last prompt khi cần.
 */
final class EditorWorkflowExecutionService
{
    public const MODE_FULL_GRAPH = 'full_graph';

    public const MODE_EXTRACT_LAST_PROMPT_BC = 'extract_last_prompt_bc';

    public function __construct(
        private readonly TaskWorkflowTestRunner $workflowRunner,
        private readonly PromptRunnerService $promptRunner,
        private readonly PromptMediaStorageService $promptMediaStorage,
    ) {}

    /**
     * @param  array<string, string>  $variables
     * @return array{
     *     media_id: int|null,
     *     url: string|null,
     *     planner_model: string|null,
     *     render_model: string|null,
     *     validation_model: string|null,
     *     usage: array<string, mixed>|null,
     *     workflow_execution_mode: string,
     *     metadata: array<string, mixed>,
     * }
     */
    public function executeForEditor(
        SeoTask $task,
        SeoArticle $article,
        array $variables,
        ImageToolType $expectedTool,
        ?SeoMedia $targetMedia = null,
    ): array {
        $context = $this->buildContext($article, $variables);

        try {
            $result = $this->executeFullGraph($task, $context, $expectedTool, $targetMedia);
            if (trim((string) ($result['url'] ?? '')) !== '') {
                return $result;
            }
        } catch (\Throwable $exception) {
            logger()->warning('Editor workflow full_graph failed, fallback BC', [
                'task_id' => (int) $task->id,
                'article_id' => (int) $article->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->executeExtractLastPromptBc($task, $context, $expectedTool, $targetMedia);
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function buildContext(SeoArticle $article, array $variables): TaskTestContext
    {
        return new TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: 'editor_media',
            variables: $variables,
            summary: 'Editor media workflow',
            siteId: (int) ($article->site_id ?? 0) > 0 ? (int) $article->site_id : null,
            postType: is_string($article->post_type ?? null) ? (string) $article->post_type : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function executeFullGraph(
        SeoTask $task,
        TaskTestContext $context,
        ImageToolType $expectedTool,
        ?SeoMedia $targetMedia,
    ): array {
        $steps = $this->promptMediaStorage->usingTargetMedia(
            $targetMedia,
            fn () => PromptMediaPersistContext::using(
                (int) ($context->siteId ?? 0),
                (int) ($context->article?->id ?? 0),
                null,
                fn () => $this->workflowRunner->run($task, $context),
            ),
        );

        $mediaStep = $this->resolveFinalMediaStep($steps, $expectedTool);
        if ($mediaStep === null) {
            throw new PromptRunException('Workflow không trả media output cuối.');
        }

        $url = trim((string) ($mediaStep['output'] ?? ''));
        if ($url === '') {
            throw new PromptRunException('Workflow media step rỗng.');
        }

        if ($targetMedia instanceof SeoMedia) {
            $url = $this->promptMediaStorage->persistRemoteMediaIfPresent(
                $url,
                $expectedTool->value,
                (string) ($mediaStep['raw_model_used'] ?? null),
                $targetMedia,
            );
        }

        return [
            'media_id' => $targetMedia?->id,
            'url' => $url,
            'planner_model' => $this->resolvePlannerModel($steps, $mediaStep),
            'render_model' => trim((string) ($mediaStep['raw_model_used'] ?? '')) ?: null,
            'validation_model' => null,
            'usage' => null,
            'workflow_execution_mode' => self::MODE_FULL_GRAPH,
            'metadata' => [
                'workflow_steps' => count($steps),
                'final_node_id' => (string) ($mediaStep['node_id'] ?? ''),
                'prompt_id' => (int) ($mediaStep['prompt_id'] ?? 0),
                'validation_owner' => 'workflow',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeExtractLastPromptBc(
        SeoTask $task,
        TaskTestContext $context,
        ImageToolType $expectedTool,
        ?SeoMedia $targetMedia,
    ): array {
        $prompt = $expectedTool === ImageToolType::Video
            ? $this->workflowRunner->resolveVideoPromptForTask($task)
            : $this->workflowRunner->resolveImagePromptForTask($task);

        logger()->warning('Editor workflow fallback extract_last_prompt_bc', [
            'task_id' => (int) $task->id,
            'prompt_id' => (int) $prompt->id,
        ]);

        $result = $this->promptMediaStorage->usingTargetMedia(
            $targetMedia,
            fn () => $this->promptRunner->run(
                $prompt,
                $context->variables,
                isTaskMode: false,
                runFullDependentChain: false,
            ),
        );

        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $url = trim((string) ($result->output_text ?? ''));

        return [
            'media_id' => $targetMedia?->id,
            'url' => $url !== '' ? $url : null,
            'planner_model' => trim((string) ($snapshot['planner_model'] ?? '')) ?: null,
            'render_model' => trim((string) ($snapshot['render_model'] ?? $snapshot['raw_model_used'] ?? '')) ?: null,
            'validation_model' => trim((string) ($snapshot['validation_model'] ?? '')) ?: null,
            'usage' => is_array($result->token_usage) ? $result->token_usage : null,
            'workflow_execution_mode' => self::MODE_EXTRACT_LAST_PROMPT_BC,
            'metadata' => [
                'prompt_id' => (int) $prompt->id,
                'result_id' => (int) $result->id,
                'validation_owner' => 'none',
                'bc_fallback' => true,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function resolveFinalMediaStep(array $steps, ImageToolType $expectedTool): ?array
    {
        $marked = null;
        $lastMedia = null;

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            if ((string) ($step['type'] ?? '') !== 'prompt') {
                continue;
            }

            if ((string) ($step['status'] ?? '') !== 'completed') {
                continue;
            }

            $promptId = (int) ($step['prompt_id'] ?? 0);
            if ($promptId <= 0) {
                continue;
            }

            $prompt = SeoPrompt::query()->find($promptId);
            if (! $prompt instanceof SeoPrompt) {
                continue;
            }

            $tool = ImageToolType::fromMixed($prompt->tools ?? 'default');
            if ($expectedTool === ImageToolType::Video) {
                if ($tool !== ImageToolType::Video) {
                    continue;
                }
            } elseif (! $tool->isImagePipeline()) {
                continue;
            }

            $output = trim((string) ($step['output'] ?? ''));
            if ($output === '' || ! $this->looksLikeMediaOutput($output)) {
                continue;
            }

            $nodeData = is_array($step['node_data'] ?? null) ? $step['node_data'] : [];
            if ((bool) ($nodeData['finalMediaOutput'] ?? $nodeData['isFinalMediaOutput'] ?? false)) {
                $marked = $step;
            }

            $lastMedia = $step;
        }

        return $marked ?? $lastMedia;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $mediaStep
     */
    private function resolvePlannerModel(array $steps, array $mediaStep): ?string
    {
        $mediaNodeId = (string) ($mediaStep['node_id'] ?? '');

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            if ((string) ($step['node_id'] ?? '') === $mediaNodeId) {
                continue;
            }

            if ((string) ($step['type'] ?? '') !== 'prompt') {
                continue;
            }

            if ((string) ($step['status'] ?? '') !== 'completed') {
                continue;
            }

            $promptId = (int) ($step['prompt_id'] ?? 0);
            if ($promptId <= 0) {
                continue;
            }

            $prompt = SeoPrompt::query()->find($promptId);
            if (! $prompt instanceof SeoPrompt) {
                continue;
            }

            $tool = ImageToolType::fromMixed($prompt->tools ?? 'default');
            if ($tool->isImagePipeline() || $tool === ImageToolType::Video) {
                continue;
            }

            $model = trim((string) ($step['raw_model_used'] ?? ''));
            if ($model !== '') {
                return $model;
            }
        }

        return null;
    }

    private function looksLikeMediaOutput(string $output): bool
    {
        $firstLine = trim(strtok($output, "\n") ?: $output);

        return str_starts_with($firstLine, '/storage/')
            || str_starts_with($firstLine, 'http://')
            || str_starts_with($firstLine, 'https://');
    }
}
