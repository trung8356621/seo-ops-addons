<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\SideEffect\UnauthorizedWordPressSideEffectException;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressExecutionContext;
use Omnichannel\Addons\Content\Support\ArticleEditorSaveContext;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use Omnichannel\Addons\Content\Services\ArticleEditorPersistService;

final class ArticleEditorSyncOrchestrator
{
    private const EDITOR_SYNC_OPTIONS = [
        'defer_inline_media_sync' => true,
        'defer_finalize_media' => true,
    ];

    public function __construct(
        private readonly ArticleEditorBundleApplyService $bundleApply,
        private readonly ArticleEditorPersistService $persist,
        private readonly WordPressArticleSyncService $syncService,
        private readonly WordPressArticleContentService $wpContent,
        private readonly SeoImageOptimizationService $imageOptimization,
        private readonly WordPressLocalMediaSyncService $localMediaSync,
        private readonly ArticleWpSyncQueueService $syncQueue,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     * @return array{
     *     success: bool,
     *     message: string,
     *     steps?: list<array<string, mixed>>,
     *     warnings?: list<string>,
     *     reload?: bool,
     *     clear_local_state?: bool,
     *     media_sync_queued?: bool,
     *     notification?: array{title: string, body: string, status: string}
     * }
     */
    public function syncFromEditorBundle(
        SeoArticle $article,
        array $bundle,
        WordPressExecutionContext $sideEffect,
        bool $fromQueue = false,
    ): array {
        if (! $fromQueue) {
            abort_if(SeoAccessControl::isContentManager(), 403);

            if (! SeoAccessControl::canSyncArticlesToWordPress()) {
                return $this->failureResponse('Vai trò Quản lý nội dung chỉ được lưu trên Laravel, không đồng bộ WordPress.');
            }
        }

        $lock = Cache::lock('seo-wp-publish-article-'.(int) $article->id, 120);

        try {
            $lock->block(30);
        } catch (LockTimeoutException) {
            return $this->failureResponse('Hết thời gian chờ đồng bộ WordPress. Vui lòng thử lại.');
        }

        try {
            return $this->runSyncPipeline($article, $bundle, $sideEffect);
        } catch (UnauthorizedWordPressSideEffectException $e) {
            return $this->failureResponse($e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    private function runSyncPipeline(SeoArticle $article, array $bundle, WordPressExecutionContext $sideEffect): array
    {
        $steps = [];
        $warnings = [];
        // Đăng ngay phải còn hiệu lực khi worker chạy — đè draft/scheduled trong bundle cũ.
        $bundle = $this->syncQueue->applyPublishImmediatelyToBundle($bundle);
        $context = ArticleEditorSaveContext::fromBundle($article, $bundle);
        $this->bundleApply->apply($article, $bundle, $context);

        $html = (string) ($bundle['html'] ?? '');
        $seoAnalysis = is_array($bundle['seo_analysis'] ?? null) ? $bundle['seo_analysis'] : null;
        $seoOverride = $context->seoPayloadForWordPress();
        $article = $article->fresh() ?? $article;

        $skipSave = $this->syncService->shouldSkipSaveLocalPhase($article, $html, $seoAnalysis);
        if ($skipSave['skip']) {
            $steps[] = $this->step('save_local', 'done', 'Bỏ qua lưu local — nội dung chưa thay đổi.', skipped: true);
        } else {
            $html = $this->persist->persistLocalSilent($article, $context, $html);
            $this->syncService->storeLocalSaveFingerprint($article->fresh(), $html, $seoAnalysis);
            app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class)
                ->articleContentUpdated($article->fresh() ?? $article);
            $steps[] = $this->step('save_local', 'done', 'Đã lưu bản nháp Laravel.');
        }

        $article = $article->fresh() ?? $article;
        $syncOptions = $this->resolveEditorSyncOptions($article);
        $ensure = $this->syncService->ensureWordPressPostForArticle($article, $sideEffect, $seoOverride, $syncOptions);
        if (! ($ensure['success'] ?? false)) {
            $steps[] = $this->step('prepare_payload', 'error', (string) ($ensure['message'] ?? 'Không liên kết được WordPress.'));

            return $this->failureResponse((string) ($ensure['message'] ?? 'Không liên kết được WordPress.'), $steps);
        }

        $wpContext = $this->syncService->resolveEditorSyncContext($article->fresh());
        if (! ($wpContext['success'] ?? false)) {
            $steps[] = $this->step('prepare_payload', 'error', (string) ($wpContext['message'] ?? 'Không lấy được context WordPress.'));

            return $this->failureResponse((string) ($wpContext['message'] ?? 'Không lấy được context WordPress.'), $steps);
        }

        $prepared = $this->syncService->prepareEditorSyncPayload($article->fresh(), $seoOverride, $syncOptions);
        $mediaErrors = is_array($prepared['local_media_sync_errors'] ?? null)
            ? $prepared['local_media_sync_errors']
            : [];
        $warnings = array_merge($warnings, $mediaErrors);

        $prepareDetail = (string) ($ensure['step_detail'] ?? '');
        if ($prepared['skip_editor_sync'] ?? false) {
            $prepareDetail .= ($prepareDetail !== '' ? ', ' : '').'editor_sync_skip=1';
        }
        if ($prepared['defer_inline_media_sync'] ?? false) {
            $prepareDetail .= ($prepareDetail !== '' ? ', ' : '').'media_sync=queued';
        }

        $article->loadMissing('site');
        if ($article->site !== null) {
            $config = $this->imageOptimization->resolveForSite((int) $article->site->id);
            if ((bool) $config->auto_convert_webp && ! $this->imageOptimization->canEncodeWebp()) {
                $prepareDetail .= ($prepareDetail !== '' ? ', ' : '').'webp_encode=unavailable';
            }
        }

        $steps[] = $this->step(
            'prepare_payload',
            'done',
            $prepareDetail !== '' ? $prepareDetail : 'Đã chuẩn bị payload (ảnh nội dung xử lý nền).',
        );

        $syncResult = $this->syncService->executeEditorSyncRequest($article->fresh(), $sideEffect, $wpContext, $prepared);
        if (! ($syncResult['success'] ?? false)) {
            $steps[] = $this->step(
                'editor_sync',
                'error',
                (string) ($syncResult['message'] ?? 'editor-sync thất bại.'),
            );

            return $this->failureResponse((string) ($syncResult['message'] ?? 'editor-sync thất bại.'), $steps);
        }

        $steps[] = $this->step(
            'editor_sync',
            'done',
            (string) ($syncResult['step_detail'] ?? ($syncResult['message'] ?? 'Đã gửi nội dung lên WordPress.')),
            skipped: (bool) ($syncResult['skipped'] ?? false),
        );

        $decoded = is_array($syncResult['decoded'] ?? null) ? $syncResult['decoded'] : [];
        $finalize = $this->syncService->completeEditorSyncResponse(
            $article->fresh(),
            $prepared,
            $decoded,
            $syncOptions,
        );

        if (! ($finalize['success'] ?? false)) {
            $steps[] = $this->step('finalize', 'error', (string) ($finalize['message'] ?? 'Hoàn tất đồng bộ thất bại.'));

            return $this->failureResponse((string) ($finalize['message'] ?? 'Hoàn tất đồng bộ thất bại.'), $steps);
        }

        $deferInlineMedia = (bool) ($syncOptions['defer_inline_media_sync'] ?? false);
        if ($deferInlineMedia) {
            // Body media must stay inside the same Automation Action — do not spawn seo-queue follow-up.
            $deferInlineMedia = false;
        }

        $remoteIdentity = $this->wpContent->refreshSlugAndPermalinkFromWordPress($article->fresh());
        $syncBody = (string) ($finalize['message'] ?? 'Đã đồng bộ lên WordPress.');
        if (! ($remoteIdentity['success'] ?? false)) {
            $syncBody .= ' Chưa tải lại được slug/permalink mới nhất từ WordPress.';
        }
        if ($deferInlineMedia) {
            $syncBody .= ' Ảnh trong nội dung đang được đồng bộ nền.';
        }

        $steps[] = $this->step('finalize', 'done', (string) ($finalize['step_detail'] ?? $syncBody));

        $article = $article->fresh() ?? $article;
        // Emit only — pending product reviews owned by automation rule on wordpress.synced.
        app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class)
            ->wordpressSynced($article, [
                'wp_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null,
                'message' => $syncBody,
                'origin' => 'orchestrator',
            ]);

        return [
            'success' => true,
            'message' => $syncBody,
            'steps' => $steps,
            'warnings' => $warnings,
            'reload' => true,
            'clear_local_state' => true,
            'media_sync_queued' => $deferInlineMedia,
            'notification' => [
                'title' => 'WordPress synced',
                'body' => $syncBody,
                'status' => 'success',
            ],
        ];
    }

    /**
     * @return array{defer_inline_media_sync: bool, defer_finalize_media: bool}
     */
    private function resolveEditorSyncOptions(SeoArticle $article): array
    {
        $body = trim((string) ($article->body ?? ''));
        if ($body !== '' && $this->localMediaSync->htmlContainsLocalSeoMedia($body)) {
            return [
                'defer_inline_media_sync' => false,
                'defer_finalize_media' => false,
            ];
        }

        return self::EDITOR_SYNC_OPTIONS;
    }

    /**
     * @return array{success: bool, message: string, steps?: list<array<string, mixed>>, notification?: array{title: string, body: string, status: string}}
     */
    private function failureResponse(string $message, array $steps = []): array
    {
        return [
            'success' => false,
            'message' => $message,
            'steps' => $steps,
            'notification' => [
                'title' => 'WordPress sync failed',
                'body' => $message,
                'status' => 'danger',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $id, string $status, string $detail, bool $skipped = false): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'detail' => $detail,
            'skipped' => $skipped,
        ];
    }
}
