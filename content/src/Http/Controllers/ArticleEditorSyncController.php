<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Controllers;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessActionDispatcher;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Content\Http\Requests\ArticleEditorActionRequest;
use Omnichannel\Addons\Seo\Http\Requests\ArticleEditorSeoMetaRequest;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\Content\Services\ArticleEditorBundleApplyService;
use Omnichannel\Addons\Content\Services\ArticleEditorSavePatchService;
use Omnichannel\Addons\Content\Services\ArticleEditorSeoMetaService;
use Omnichannel\Addons\Content\Services\ArticleEditorSeoPayloadService;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\WordPress\Services\WordPressManualSyncService;
use Omnichannel\Addons\Content\Support\ArticleEditorSaveContext;
use Omnichannel\Addons\Content\Support\ArticleEditorSessionErrorCode;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RuntimeLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * REST lưu / đồng bộ bài viết từ React Editor.
 *
 * - POST /api/seo/articles/{article}/save
 * - POST /api/seo/articles/{article}/sync-wp  (manual WordPressManualSyncService)
 * - POST /api/seo/articles/{article}/seo-meta
 *
 * save() / saveSeoMeta() đi qua BusinessActionDispatcher (article.content.update /
 * article.seo_meta.update) — controller không còn ghi trực tiếp qua service.
 */
final class ArticleEditorSyncController extends Controller
{
    public function __construct(
        private readonly ArticleEditorBundleApplyService $bundleApply,
        private readonly ArticleEditorSavePatchService $savePatch,
        private readonly ArticleEditorSeoMetaService $seoMeta,
        private readonly WordPressManualSyncService $manualSync,
        private readonly BusinessActionDispatcher $actions,
    ) {}

    public function save(ArticleEditorActionRequest $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $sessionBlock = $this->rejectLegacySaveWhenSessionActive($request, $article);
        if ($sessionBlock instanceof JsonResponse) {
            return $sessionBlock;
        }

        $bundle = $request->editorBundle();
        $context = ArticleEditorSaveContext::fromBundle($article, $bundle);
        $html = (string) ($bundle['html'] ?? '');
        $seoAnalysis = is_array($bundle['seo_analysis'] ?? null) ? $bundle['seo_analysis'] : null;

        // Conflict-gated content write first — avoid side-effect writes when 409.
        $result = $this->actions->dispatch(
            'article.content.update',
            $this->buildContentUpdateInput($article, $bundle, $html),
            $this->buildActionContext($request, $article),
        );

        if (! $result->success) {
            $code = (string) ($result->error['code'] ?? '');
            $message = (string) ($result->error['message'] ?? 'Không lưu được bài viết.');

            if (in_array($code, ['conflict_updated_at', 'conflict_content_hash', 'conflict_document_version'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'conflict' => $result->error,
                    'error' => $code === 'conflict_document_version'
                        ? ArticleEditorSessionErrorCode::DOCUMENT_VERSION_CONFLICT
                        : ($code === 'conflict_content_hash'
                            ? ArticleEditorSessionErrorCode::CONTENT_HASH_CONFLICT
                            : $code),
                    'notification' => [
                        'title' => 'Xung đột khi lưu',
                        'body' => $message,
                        'status' => 'warning',
                    ],
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => $message,
                'notification' => [
                    'title' => 'Không lưu được nội dung',
                    'body' => $message,
                    'status' => 'warning',
                ],
            ], 422);
        }

        $savedArticle = $article->fresh() ?? $article;
        $this->bundleApply->apply($savedArticle, $bundle, $context);

        $savedArticle = $savedArticle->fresh() ?? $savedArticle;
        $persistedSeo = null;
        try {
            $persistedSeo = app(SeoAnalyzerService::class)->analyzeSubmittedContent(
                $savedArticle,
                $html !== '' ? $html : (string) ($savedArticle->body ?? ''),
                trim((string) ($context->title !== '' ? $context->title : ($savedArticle->title ?? ''))),
                $context->normalizedSlug() !== '' ? $context->normalizedSlug() : trim((string) ($savedArticle->slug ?? '')),
                trim((string) ($context->seoMetaDescription ?? '')) !== ''
                    ? trim((string) $context->seoMetaDescription)
                    : null,
            );
        } catch (\Throwable $e) {
            RuntimeLogger::warning('seo.editor.save_score_failed', [
                'article_id' => (int) $savedArticle->id,
                'error' => $e->getMessage(),
            ]);
            $persistedSeo = is_array($seoAnalysis) ? $seoAnalysis : null;
        }

        $message = (string) ($result->output['message'] ?? 'Article saved');
        $handoff = is_array($result->output['content_project_handoff'] ?? null)
            ? $result->output['content_project_handoff']
            : null;

        return response()->json([
            'success' => true,
            'message' => $message,
            'reload' => false,
            'patch' => $this->savePatch->build(
                $savedArticle->fresh() ?? $savedArticle,
                $context,
                $persistedSeo,
            ),
            'content_project_handoff' => $handoff,
            'notification' => [
                'title' => 'Article saved',
                'body' => $message,
                'status' => 'success',
            ],
        ]);
    }

    public function syncWp(ArticleEditorActionRequest $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        /** @var User $actor */
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $result = $this->manualSync->enqueueFromEditorBundle(
            $article,
            $request->editorBundle(),
            $actor,
            'article_editor.sync_wordpress',
        );
        $statusCode = ($result['success'] ?? false) ? 200 : 422;
        $dispatchStatus = (string) ($result['status'] ?? (($result['success'] ?? false) ? 'dispatched' : 'blocked'));
        $saveMode = (string) ($result['save_mode'] ?? ($result['data']['save_mode'] ?? ''));
        $workspaceOnly = (bool) ($result['workspace_only'] ?? false)
            || $dispatchStatus === 'workspace_saved'
            || $saveMode === 'project_local_save';

        if ($dispatchStatus === 'blocked') {
            $result['queued'] = false;
            $result['close_editor'] = false;
            $result['data'] = $result['data'] ?? null;
            $result['notification'] = $result['notification'] ?? [
                'title' => __('seo-content-ai::filament.automation.wp_sync_blocked_title'),
                'body' => (string) ($result['message'] ?? __('seo-content-ai::filament.automation.wp_sync_blocked_body')),
                'status' => 'danger',
            ];
        } elseif ($workspaceOnly) {
            // Content Project local save — never pretend it entered the legacy WP sync queue.
            $result['queued'] = false;
            $result['already_queued'] = false;
            $result['workspace_only'] = true;
            $result['save_mode'] = $saveMode !== '' ? $saveMode : 'project_local_save';
            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            $proven = (bool) ($result['success'] ?? false)
                && (int) ($data['article_id'] ?? 0) > 0
                && trim((string) ($data['content_hash'] ?? '')) !== ''
                && trim((string) ($data['saved_at'] ?? '')) !== '';
            $result['close_editor'] = $proven;
            $result['reload'] = false;
            if (! $proven) {
                $result['success'] = false;
                $result['status'] = 'blocked';
                $statusCode = 422;
                $result['notification'] = [
                    'title' => __('seo-content-ai::filament.automation.content_project_workspace_save_failed_title'),
                    'body' => (string) ($result['message'] ?? 'Workspace save was not confirmed.'),
                    'status' => 'danger',
                ];
            } elseif (! isset($result['notification']) || ! is_array($result['notification'])) {
                $result['notification'] = [
                    'title' => __('seo-content-ai::filament.automation.content_project_workspace_saved_title'),
                    'body' => (string) ($result['message'] ?? __('seo-content-ai::filament.automation.content_project_workspace_saved')),
                    'status' => 'success',
                ];
            }
        } elseif (in_array($dispatchStatus, ['post_publish_synced', 'rewrite_existing_synced'], true)) {
            $result['queued'] = false;
            $result['already_queued'] = false;
            $result['workspace_only'] = false;
            $result['reload'] = false;
            $result['close_editor'] = false;
            if (! isset($result['notification']) || ! is_array($result['notification'])) {
                $result['notification'] = [
                    'title' => 'Đã đồng bộ bài viết lên WordPress.',
                    'body' => (string) ($result['message'] ?? 'Đã đồng bộ bài viết lên WordPress.'),
                    'status' => 'success',
                ];
            }
        } elseif ($dispatchStatus === 'deduplicated') {
            $result['queued'] = true;
            $result['reload'] = false;
            $result['close_editor'] = true;
            $result['already_queued'] = true;
            $result['notification'] = [
                'title' => __('seo-content-ai::filament.automation.manual_sync_queued_title'),
                'body' => (string) ($result['message'] ?? __('seo-content-ai::filament.automation.manual_sync_already_queued')),
                'status' => 'info',
            ];
        } else {
            $result['queued'] = true;
            $result['reload'] = false;
            $result['close_editor'] = true;
            $result['already_queued'] = false;
            $result['notification'] = [
                'title' => __('seo-content-ai::filament.automation.manual_sync_queued_title'),
                'body' => (string) ($result['message'] ?? __('seo-content-ai::filament.automation.manual_sync_queued')),
                'status' => 'success',
            ];
        }

        return response()->json($result, $statusCode);
    }

    /**
     * Full SEO + link catalogs — on-demand when Links panel opens / Refresh.
     * Not used for initial editor bootstrap (see forEditorBootstrap).
     */
    public function seoPayload(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return response()->json([
            'success' => true,
            'data' => app(ArticleEditorSeoPayloadService::class)->forArticle($article),
        ]);
    }

    public function saveSeoMeta(ArticleEditorSeoMetaRequest $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $result = $this->actions->dispatch(
            'article.seo_meta.update',
            [
                'article_id' => (int) $article->id,
                'focus_keyword' => $request->focusKeyword(),
                'meta_description' => $request->metaDescription(),
                'slug' => $request->slug(),
            ],
            $this->buildActionContext($request, $article),
        );

        if (! $result->success) {
            $message = (string) ($result->error['message'] ?? 'Không lưu được trường SEO.');

            return response()->json([
                'success' => false,
                'message' => $message,
                'notification' => [
                    'title' => 'Không lưu được SEO fields',
                    'body' => $message,
                    'status' => 'warning',
                ],
            ], 422);
        }

        $output = is_array($result->output) ? $result->output : [];
        $fresh = $article->fresh(['articleMetas', 'site']) ?? $article;

        try {
            $payload = $this->seoMeta->buildResponse(
                $fresh,
                (string) ($output['focus_keyword'] ?? $request->focusKeyword()),
                (string) ($output['meta_description'] ?? $request->metaDescription()),
                (string) ($output['slug'] ?? $request->slug()),
            );
        } catch (\Throwable $exception) {
            RuntimeLogger::report($exception, [
                'action' => 'article.seo_meta.update',
                'article_id' => (int) $article->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'SEO fields đã lưu nhưng không dựng được preview response.',
                'error_code' => 'seo_meta_response_failed',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'SEO fields saved',
            ...$payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    private function buildContentUpdateInput(SeoArticle $article, array $bundle, string $html): array
    {
        $meta = is_array($bundle['article_meta'] ?? null) ? $bundle['article_meta'] : [];
        $publishBox = is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [];

        $input = [
            'article_id' => (int) $article->id,
            'content' => $html,
        ];

        foreach (['title', 'slug', 'seo_meta_description', 'focus_keyword'] as $field) {
            if (array_key_exists($field, $meta)) {
                $input[$field] = (string) $meta[$field];
            }
        }

        foreach (['status', 'post_type', 'visibility', 'publish_day', 'publish_month', 'publish_year', 'publish_hour', 'publish_minute'] as $field) {
            if (array_key_exists($field, $publishBox)) {
                $input[$field] = (string) $publishBox[$field];
            }
        }

        foreach (['expected_updated_at', 'expected_content_hash', 'expected_document_version'] as $field) {
            if (array_key_exists($field, $bundle) && $bundle[$field] !== null && $bundle[$field] !== '') {
                $input[$field] = $bundle[$field];
            }
        }

        return $input;
    }

    /**
     * User-facing legacy save must not bypass an active editor session.
     * Allow only when request carries the owning session id.
     */
    private function rejectLegacySaveWhenSessionActive(Request $request, SeoArticle $article): ?JsonResponse
    {
        /** @var ArticleEditorSessionService $sessions */
        $sessions = app(ArticleEditorSessionService::class);
        $active = $sessions->findActiveSession($article);
        if ($active === null) {
            return null;
        }

        $provided = (string) ($request->input('editor_session_id')
            ?: $request->header('X-Editor-Session-Id')
            ?: '');
        $user = $request->user();

        if (
            $provided !== ''
            && $user instanceof User
            && (int) $active->user_id === (int) $user->getKey()
            && $active->isActiveLock()
        ) {
            return null;
        }

        if ($user instanceof User && (int) $active->user_id === (int) $user->getKey()) {
            return null;
        }

        RuntimeLogger::warning('seo.editor.legacy_save_blocked_by_session', [
            'article_id' => (int) $article->getKey(),
            'active_session_id' => (string) $active->id,
        ]);

        return response()->json([
            'success' => false,
            'error' => ArticleEditorSessionErrorCode::LOCKED,
            'message' => 'Article has an active editor session; use session document endpoint.',
            'lock' => [
                'editor_name' => 'Active editor session',
                'acquired_at' => $active->acquired_at?->toIso8601String(),
                'heartbeat_at' => $active->heartbeat_at?->toIso8601String(),
                'expires_at' => $active->expires_at?->toIso8601String(),
                'can_takeover' => $sessions->userCanTakeover($user instanceof User ? $user : null),
            ],
        ], 423);
    }

    private function buildActionContext(Request $request, SeoArticle $article): ActionContext
    {
        $actor = $request->user();

        return ActionContext::fromArray([
            'origin' => 'article_editor',
            'actor_id' => $actor instanceof User ? (int) $actor->id : null,
            'site_id' => $article->site_id !== null ? (int) $article->site_id : null,
            'correlation_id' => (string) ($request->header('X-Correlation-Id') ?: Str::uuid()->toString()),
        ]);
    }
}
