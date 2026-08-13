<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Jobs;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\Media\Services\ArticleEditorMediaAiService;
use Omnichannel\Addons\Content\Services\ArticleEditorReadinessService;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use Omnichannel\Addons\Content\Services\EditorWorkflowExecutionService;
use Omnichannel\Addons\AiPrompt\Services\PromptMediaStorageService;
use Omnichannel\Addons\AiPrompt\Services\PromptPostProcessingApplyService;
use Omnichannel\Addons\AiPrompt\Services\PromptResultLinkService;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Omnichannel\Addons\Media\Support\ImagenProviderErrorClassifier;
use Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Str;
use Throwable;

class GenerateMediaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Must exceed GeminiMediaGenerationService HTTP budget × số model dự phòng. */
    public int $timeout = 360;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        protected int $seoMediaId,
        protected int $promptId,
        protected array $variables,
        protected string $toolType = 'image',
        /** Editor jobs need one image — not the full text→image chain (can run 10+ minutes). */
        protected bool $runFullDependentChain = false,
    ) {}

    public function handle(
        PromptRunnerService $promptRunner,
        PromptMediaStorageService $promptMediaStorage,
        PromptPostProcessingApplyService $postProcessing,
        PromptResultLinkService $promptResultLinks,
        SeoDatabaseConnectionService $databaseConnection,
        ArticleEditorReadinessService $editorReadiness,
        EditorWorkflowExecutionService $workflowExecution,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();

        $media = SeoMedia::query()->find($this->seoMediaId);
        if ($media instanceof SeoMedia && (int) ($media->site_id ?? 0) > 0) {
            $databaseConnection->bootstrapSeoDatabaseConnection((int) $media->site_id);
            $media = SeoMedia::query()->find($this->seoMediaId);
        }

        $prompt = SeoPrompt::query()->find($this->promptId);

        if (! $media instanceof SeoMedia || ! $prompt instanceof SeoPrompt) {
            return;
        }

        if ((int) ($media->prompt_id ?? 0) <= 0 || ! is_array($media->prompt_variables) || $media->prompt_variables === []) {
            $media->update([
                'status' => 'failed',
                'error_message' => 'Job AI không hợp lệ (thiếu prompt). Xóa hoặc tạo ảnh mới.',
            ]);

            return;
        }

        $status = strtolower(trim((string) ($media->status ?? '')));
        if ($status === 'failed') {
            // Job đã bị thay thế / hủy trước khi worker chạy — không ghi đè thư viện.
            return;
        }

        if ($status === 'completed' && ! str_contains((string) ($media->url ?? ''), 'placeholder-loading')) {
            return;
        }

        try {
            $runVariables = $this->ensureQuickSplitSnapshot($media, $prompt, $this->variables);

            $mediaSource = (string) ($runVariables[SeoCreateArticleSettingsService::EDITOR_VAR_MEDIA_SOURCE] ?? SeoCreateArticleSettingsService::SOURCE_PROMPT);
            $workflowTaskId = (int) ($runVariables[SeoCreateArticleSettingsService::EDITOR_VAR_WORKFLOW_TASK_ID] ?? 0);
            $articleId = (int) ($media->firstArticleId() ?? 0);
            $article = $articleId > 0 ? SeoArticle::query()->find($articleId) : null;

            if (
                $mediaSource === SeoCreateArticleSettingsService::SOURCE_WORKFLOW
                && $workflowTaskId > 0
                && $article instanceof SeoArticle
            ) {
                $workflowResult = $this->runEditorWorkflow(
                    workflowExecution: $workflowExecution,
                    media: $media,
                    article: $article,
                    taskId: $workflowTaskId,
                );
                $finalUrl = trim((string) ($workflowResult['url'] ?? ''));
                $workflowSnapshot = is_array($workflowResult['snapshot'] ?? null) ? $workflowResult['snapshot'] : [];
            } else {
                $linkedResultId = (int) ($runVariables['_linked_prompt_result_id'] ?? 0);
                $promptResult = $promptMediaStorage->usingTargetMedia(
                    $media,
                    fn () => $promptRunner->run(
                        $prompt,
                        $runVariables,
                        isTaskMode: false,
                        runFullDependentChain: $this->runFullDependentChain,
                        reuseResultId: $linkedResultId > 0 ? $linkedResultId : null,
                    ),
                );
                $output = trim((string) ($promptResult->output_text ?? ''));
                $finalUrl = trim((string) (explode("\n", $output, 2)[0] ?? ''));
                $workflowSnapshot = is_array($promptResult->input_snapshot) ? $promptResult->input_snapshot : [];

                if ($articleId > 0 && $linkedResultId <= 0) {
                    try {
                        $promptResultLinks->linkPromptResult(
                            promptResultId: (int) $promptResult->id,
                            articleId: $articleId,
                            source: 'editor_media_generation',
                            meta: [
                                'tool_type' => $this->toolType,
                                'seo_media_id' => (int) $media->id,
                                'editor_block_id' => (string) ($media->editor_block_id ?? ''),
                            ],
                        );
                    } catch (Throwable $linkException) {
                        logger()->warning(
                            "GenerateMediaJob linkPromptResult failed [media_id={$this->seoMediaId}]: {$linkException->getMessage()}",
                        );
                    }
                }
            }

            if ($finalUrl === '' || str_contains($finalUrl, 'placeholder-loading')) {
                throw new PromptRunException('Không nhận được URL kết quả từ AI.');
            }

            $urlPath = parse_url($finalUrl, PHP_URL_PATH);
            $urlPath = is_string($urlPath) ? $urlPath : '';
            $isStoragePath = Str::startsWith($urlPath, '/storage/');
            $resolvedPath = $isStoragePath
                ? ltrim(substr($urlPath, strlen('/storage/')), '/')
                : (string) $media->path;
            $resolvedFilename = basename($urlPath !== '' ? $urlPath : $finalUrl);

            $media->update([
                'url' => $finalUrl,
                'path' => $resolvedPath !== '' ? $resolvedPath : (string) $media->path,
                'filename' => $resolvedFilename !== '' ? $resolvedFilename : (string) $media->filename,
                'status' => 'completed',
                'error_message' => null,
                'prompt_variables' => $this->mergeWorkflowSnapshotIntoVariables($media, $workflowSnapshot),
            ]);

            $media = $media->fresh();
            if (ImageToolType::fromMixed($this->toolType)->isImagePipeline() && $media instanceof SeoMedia) {
                $isProductGallery = trim((string) ($media->editor_block_id ?? ''))
                    === ArticleEditorMediaAiService::PRODUCT_GALLERY_EDITOR_BLOCK_ID;

                if ($isProductGallery) {
                    $this->runProductGalleryMode1Pipeline($media, $prompt, $articleId);
                } else {
                    try {
                        $postResult = $postProcessing->applyIfConfigured($media, $prompt);
                        $variables = is_array($media->prompt_variables) ? $media->prompt_variables : [];
                        if ($postResult->applied && count($postResult->pieces) > 0) {
                            $variables['post_processing_piece_ids'] = array_values(array_map(
                                static fn (SeoMedia $piece): int => (int) $piece->id,
                                $postResult->pieces,
                            ));
                            unset($variables['quick_split_error'], $variables['quick_split_error_code']);
                            $media->update(['prompt_variables' => $variables]);
                        } elseif ($postResult->errorCode !== null || filled($postResult->message)) {
                            $variables['quick_split_error'] = (string) $postResult->message;
                            $variables['quick_split_error_code'] = (string) ($postResult->errorCode ?? 'QUICK_SPLIT_FAILED');
                            $media->update(['prompt_variables' => $variables]);
                        }
                    } catch (Throwable $postProcessingException) {
                        logger()->warning(
                            "GenerateMediaJob post-processing failed [media_id={$this->seoMediaId}]: {$postProcessingException->getMessage()}",
                        );
                    }
                }
            }

            $media = $media->fresh();
            if (ImageToolType::fromMixed($this->toolType)->isImagePipeline() && $media instanceof SeoMedia) {
                $isProductGallery = trim((string) ($media->editor_block_id ?? ''))
                    === ArticleEditorMediaAiService::PRODUCT_GALLERY_EDITOR_BLOCK_ID;
                if (! $isProductGallery) {
                    $this->persistProductGalleryLinkIfNeeded($media);
                }
            }

            if ($articleId > 0 && $media instanceof SeoMedia) {
                $article = SeoArticle::query()->find($articleId);
                if ($article instanceof SeoArticle) {
                    $editorReadiness->evaluate($article);
                }
            }
        } catch (Throwable $exception) {
            $media->update([
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            logger()->error(
                "GenerateMediaJob failed [media_id={$this->seoMediaId}, tool={$this->toolType}]: {$exception->getMessage()}",
            );

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $media = SeoMedia::query()->find($this->seoMediaId);
        if (! $media instanceof SeoMedia) {
            return;
        }

        $rawMessage = trim((string) $exception->getMessage());

        if ($exception instanceof PromptRunException) {
            $userMessage = $exception->userMessage();
            $technical = $exception->technicalDetails();
            $audit = $exception->audit();
            $variables = is_array($media->prompt_variables) ? $media->prompt_variables : [];
            if ($audit !== []) {
                $variables['imagen_provider_audit'] = $audit;
            }
            $variables['imagen_technical_details'] = ImagenProviderErrorClassifier::redactSecrets($technical);
            $media->update([
                'status' => 'failed',
                'error_message' => mb_substr($userMessage, 0, 1000),
                'prompt_variables' => $variables,
            ]);

            return;
        }

        $presented = ImagenProviderErrorClassifier::present($rawMessage);

        $message = match (true) {
            $exception instanceof TimeoutExceededException => 'Job AI bị timeout khi xử lý (quá lâu). Chạy queue worker với --timeout=360 rồi bấm Thử lại.',
            str_contains($rawMessage, 'attempted too many times') => 'Job AI bị hủy giữa chừng (queue worker timeout). Khởi động lại worker với --timeout=360 rồi bấm Thử lại.',
            str_contains(strtolower($rawMessage), 'curl error 28')
                || str_contains(strtolower($rawMessage), 'timed out') => 'Gemini API không phản hồi kịp. Thử lại sau hoặc đổi model Imagen 4 trong Cấu hình AI.',
            $presented['classification'] === ImagenProviderErrorClassifier::PROVIDER_TRANSIENT => $presented['user_message'],
            default => $presented['user_message'] !== '' ? $presented['user_message'] : $rawMessage,
        };

        if ($message === '') {
            $message = 'Job AI thất bại. Vui lòng bấm Thử lại.';
        }

        $variables = is_array($media->prompt_variables) ? $media->prompt_variables : [];
        $variables['imagen_technical_details'] = ImagenProviderErrorClassifier::redactSecrets($rawMessage);

        $media->update([
            'status' => 'failed',
            'error_message' => mb_substr($message, 0, 1000),
            'prompt_variables' => $variables,
        ]);
    }

    /**
     * @return array{url: string|null, snapshot: array<string, mixed>}
     */
    private function runEditorWorkflow(
        EditorWorkflowExecutionService $workflowExecution,
        SeoMedia $media,
        SeoArticle $article,
        int $taskId,
    ): array {
        $task = SeoTask::query()->find($taskId);
        if (! $task instanceof SeoTask) {
            throw new PromptRunException('Workflow editor không tồn tại.');
        }

        $expectedTool = ImageToolType::fromMixed($this->toolType);
        $result = $workflowExecution->executeForEditor(
            task: $task,
            article: $article,
            variables: $this->variables,
            expectedTool: $expectedTool,
            targetMedia: $media,
        );

        $snapshot = [
            'workflow_execution_mode' => (string) ($result['workflow_execution_mode'] ?? ''),
            'render_model' => $result['render_model'] ?? null,
            'planner_model' => $result['planner_model'] ?? null,
            'validation_model' => $result['validation_model'] ?? null,
            'tools' => $this->toolType,
            'editor_media_source' => SeoCreateArticleSettingsService::SOURCE_WORKFLOW,
            'workflow_task_id' => $taskId,
        ];

        $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        foreach ([
            'candidate_count',
            'winner_score',
            'validation_passed',
            'validation_warning',
            'missing_text_count',
            'mismatched_text_count',
            'typography_complexity_summary',
        ] as $key) {
            if (array_key_exists($key, $metadata)) {
                $snapshot[$key] = $metadata[$key];
            }
        }

        return [
            'url' => is_string($result['url'] ?? null) ? $result['url'] : null,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function mergeWorkflowSnapshotIntoVariables(SeoMedia $media, array $snapshot): array
    {
        $variables = is_array($media->prompt_variables) ? $media->prompt_variables : [];
        if ($snapshot === []) {
            return $variables;
        }

        $variables['_editor_run_snapshot'] = json_encode($snapshot, JSON_UNESCAPED_UNICODE);

        return $variables;
    }

    /**
     * Freeze Quick Split config at run start so splitter uses dispatch-time grid, not later prompt edits.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function ensureQuickSplitSnapshot(SeoMedia $media, SeoPrompt $prompt, array $variables): array
    {
        if (! ImageToolType::fromMixed($this->toolType)->isImagePipeline()) {
            return $variables;
        }

        $mediaVariables = is_array($media->prompt_variables) ? $media->prompt_variables : [];
        $merged = array_merge($mediaVariables, $variables);

        if (PromptPostProcessing::fromVariablesSnapshot($merged) === null) {
            $merged = PromptPostProcessing::attachSnapshotToVariables(
                $merged,
                PromptPostProcessing::fromPrompt($prompt),
            );
        }

        $existing = $mediaVariables[PromptPostProcessing::SNAPSHOT_VARIABLE_KEY] ?? null;
        $next = $merged[PromptPostProcessing::SNAPSHOT_VARIABLE_KEY] ?? null;
        if ($existing === null && $next !== null) {
            $media->update(['prompt_variables' => $merged]);
            $media->refresh();
        }

        return $merged;
    }

    private function persistProductGalleryLinkIfNeeded(SeoMedia $media): void
    {
        if (trim((string) ($media->editor_block_id ?? '')) !== ArticleEditorMediaAiService::PRODUCT_GALLERY_EDITOR_BLOCK_ID) {
            return;
        }

        $articleId = (int) ($media->firstArticleId() ?? 0);
        if ($articleId <= 0) {
            return;
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return;
        }

        try {
            app(ArticleMediaLocalService::class)->appendGeneratedImageToProductAlbum($article, $media);
        } catch (Throwable $exception) {
            logger()->warning(
                "GenerateMediaJob product gallery link failed [media_id={$this->seoMediaId}]: {$exception->getMessage()}",
            );
        }
    }

    private function runProductGalleryMode1Pipeline(SeoMedia $media, SeoPrompt $prompt, int $articleId): void
    {
        if ($articleId <= 0) {
            $articleId = (int) ($media->firstArticleId() ?? 0);
        }
        if ($articleId <= 0) {
            return;
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return;
        }

        try {
            app(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPipelineService::class)
                ->runAfterSpriteSaved($media, $prompt, $article);
        } catch (Throwable $exception) {
            logger()->warning(
                "GenerateMediaJob product gallery Mode 1 failed [media_id={$this->seoMediaId}]: {$exception->getMessage()}",
            );

            // Never fail the job: keep sprite completed; try original fallback if snapshot exists.
            try {
                $variables = is_array($media->prompt_variables) ? $media->prompt_variables : [];
                $state = \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::readFromVariables($variables);
                $snapshot = $state['fallback_snapshot'];
                $items = [];
                foreach ($snapshot['urls'] as $index => $url) {
                    $url = trim((string) $url);
                    if ($url === '') {
                        continue;
                    }
                    $items[] = [
                        'id' => (int) ($snapshot['media_ids'][$index] ?? 0),
                        'url' => $url,
                    ];
                }
                if ($items !== []) {
                    app(ArticleMediaLocalService::class)->replaceProductAlbumLocal($article, $items);
                    $next = [
                        'gallery_ready' => true,
                        'gallery_source' => \Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySource::OriginalImages->value,
                        'gallery_generation_mode' => \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGenerationMode::Sprite->value,
                        'gallery_quality' => \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryQuality::Fallback->value,
                        'fallback_snapshot' => $snapshot,
                    ];
                    $variables = \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::mergeIntoVariables($variables, $next);
                    $media->update(['prompt_variables' => $variables]);
                    \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::mirrorToArticle($article, $next);
                }
            } catch (Throwable) {
                // ignore secondary fallback errors
            }
        }
    }
}
