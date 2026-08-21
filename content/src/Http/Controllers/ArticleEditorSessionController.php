<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Controllers;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessActionDispatcher;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Content\Http\Requests\ArticleEditorActionRequest;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionException;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\Content\Services\ArticleEditorBundleApplyService;
use Omnichannel\Addons\Content\Services\ArticleEditorSavePatchService;
use Omnichannel\Addons\Content\Support\ArticleEditorSaveContext;
use Omnichannel\Addons\Content\Support\ArticleEditorSessionErrorCode;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Editor session lock + versioned document APIs (Phase 1).
 */
final class ArticleEditorSessionController extends Controller
{
    public function __construct(
        private readonly ArticleEditorSessionService $sessions,
        private readonly ArticleEditorSavePatchService $savePatch,
        private readonly ArticleEditorBundleApplyService $bundleApply,
        private readonly BusinessActionDispatcher $actions,
    ) {}

    public function store(Request $request, SeoArticle $article): JsonResponse
    {
        try {
            abort_unless(SeoAccessControl::canAccessArticle($article), 403);
            $user = $this->requireUser($request);
            $tabId = (string) $request->input('tab_id', $request->input('client_instance_id', ''));

            try {
                $payload = $this->sessions->acquire(
                    $article,
                    $user,
                    $tabId,
                    $request->input('known_document_version'),
                    $request->userAgent(),
                );
            } catch (ArticleEditorSessionException $exception) {
                \App\Support\RuntimeLogger::warning('seo.editor.session_acquire_rejected', [
                    'article_id' => (int) $article->getKey(),
                    'error' => $exception->errorCode,
                    'status' => $exception->httpStatus,
                    'message' => $exception->getMessage(),
                    'tab_id' => $tabId,
                    'auth_id' => auth()->id(),
                    'has_csrf' => $request->headers->has('X-CSRF-TOKEN'),
                    'content_type' => (string) $request->header('Content-Type', ''),
                    'seo_connection' => (string) $request->header('X-SEO-Connection', ''),
                ]);

                return $this->errorResponse($exception);
            }

            return response()->json($payload);
        } catch (\Throwable $exception) {
            \App\Support\RuntimeLogger::report($exception, [
                'source' => 'ArticleEditorSessionController::store',
                'article_id' => (int) $article->getKey(),
                'tab_id' => (string) $request->input('tab_id', $request->input('client_instance_id', '')),
                'auth_id' => auth()->id(),
                'input_keys' => array_keys($request->all()),
            ]);

            throw $exception;
        }
    }

    public function heartbeat(Request $request, SeoArticle $article, string $session): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        $user = $this->requireUser($request);

        try {
            $payload = $this->sessions->heartbeat($article, $session, $user);
        } catch (ArticleEditorSessionException $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json($payload);
    }

    public function document(ArticleEditorActionRequest $request, SeoArticle $article, string $session): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        $user = $this->requireUser($request);

        $bundle = $request->editorBundle();
        $saveMode = (string) $request->input('save_mode', 'autosave');
        if (! in_array($saveMode, ['autosave', 'explicit'], true)) {
            $saveMode = 'autosave';
        }

        try {
            $payload = $this->sessions->saveDocument(
                $article,
                $session,
                $user,
                $bundle,
                $request->input('expected_document_version', $bundle['expected_document_version'] ?? null),
                $this->resolveExpectedContentHash($request, $bundle),
                $saveMode,
                fn (SeoArticle $lockedArticle, array $document): array => $this->persistDocument(
                    $request,
                    $lockedArticle,
                    $document,
                ),
            );
        } catch (ArticleEditorSessionException $exception) {
            return $this->errorResponse($exception);
        }

        // Same-content ACK: skip bundle side-effects + heavy save patch rebuild.
        if (($payload['noop'] ?? false) === true) {
            return response()->json([
                ...$payload,
                'patch' => [
                    'article' => [
                        'document_version' => $payload['document_version'] ?? null,
                        'updated_at' => $payload['saved_at'] ?? null,
                        'content_hash' => $payload['content_hash'] ?? null,
                        'editor_document_hash' => $payload['editor_document_hash'] ?? null,
                    ],
                ],
            ]);
        }

        $savedArticle = $article->fresh() ?? $article;
        $context = ArticleEditorSaveContext::fromBundle($savedArticle, $bundle);
        $this->bundleApply->apply($savedArticle, $bundle, $context);
        $seoAnalysis = is_array($bundle['seo_analysis'] ?? null) ? $bundle['seo_analysis'] : null;

        return response()->json([
            ...$payload,
            'patch' => $this->savePatch->build(
                $savedArticle->fresh() ?? $savedArticle,
                $context,
                $seoAnalysis,
            ),
        ]);
    }

    public function close(ArticleEditorActionRequest $request, SeoArticle $article, string $session): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        $user = $this->requireUser($request);

        $bundle = $request->editorBundle();
        $closeReason = (string) $request->input('close_reason', 'save_and_close');

        try {
            $payload = $this->sessions->close(
                $article,
                $session,
                $user,
                $bundle,
                $request->input('expected_document_version', $bundle['expected_document_version'] ?? null),
                $this->resolveExpectedContentHash($request, $bundle),
                $closeReason,
                fn (SeoArticle $lockedArticle, array $document): array => $this->persistDocument(
                    $request,
                    $lockedArticle,
                    $document,
                ),
            );
        } catch (ArticleEditorSessionException $exception) {
            return $this->errorResponse($exception);
        }

        $savedArticle = $article->fresh() ?? $article;
        $context = ArticleEditorSaveContext::fromBundle($savedArticle, $bundle);
        $this->bundleApply->apply($savedArticle, $bundle, $context);

        return response()->json($payload);
    }

    public function destroy(Request $request, SeoArticle $article, string $session): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        $user = $this->requireUser($request);

        try {
            $this->sessions->release($article, $session, $user);
        } catch (ArticleEditorSessionException $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json(['released' => true]);
    }

    /**
     * @deprecated Exclusive lock UI has no takeover path. Keep for API/admin escape hatch until product/ops ACK.
     */
    public function takeover(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        $user = $this->requireUser($request);

        try {
            $payload = $this->sessions->takeover(
                $article,
                $user,
                (string) $request->input('client_instance_id', ''),
                $request->input('known_document_version'),
                (bool) $request->boolean('confirmation'),
                $request->userAgent(),
            );
        } catch (ArticleEditorSessionException $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json($payload);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array{success: bool, message?: string, content_hash?: string, content_project_handoff?: array<string, mixed>|null}
     */
    private function persistDocument(Request $request, SeoArticle $article, array $bundle): array
    {
        $html = (string) ($bundle['html'] ?? $bundle['document'] ?? '');
        $input = $this->buildContentUpdateInput($article, $bundle, $html);

        $result = $this->actions->dispatch(
            'article.content.update',
            $input,
            $this->buildActionContext($request, $article),
        );

        if (! $result->success) {
            $code = (string) ($result->error['code'] ?? '');
            if (in_array($code, ['conflict_updated_at', 'conflict_content_hash'], true)) {
                throw ArticleEditorSessionException::make(
                    $code === 'conflict_content_hash'
                        ? ArticleEditorSessionErrorCode::CONTENT_HASH_CONFLICT
                        : ArticleEditorSessionErrorCode::DOCUMENT_VERSION_CONFLICT,
                    (string) ($result->error['message'] ?? 'Conflict'),
                    is_array($result->error) ? $result->error : [],
                    409,
                );
            }

            return [
                'success' => false,
                'message' => (string) ($result->error['message'] ?? 'Persist failed.'),
                'code' => $code !== '' ? $code : 'persist_rejected',
            ];
        }

        return [
            'success' => true,
            'message' => (string) ($result->output['message'] ?? 'Article saved'),
            'content_hash' => (string) ($result->output['content_hash'] ?? ''),
            'content_project_handoff' => is_array($result->output['content_project_handoff'] ?? null)
                ? $result->output['content_project_handoff']
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    private function buildContentUpdateInput(SeoArticle $article, array $bundle, string $html): array
    {
        $meta = is_array($bundle['meta'] ?? null) ? $bundle['meta'] : [];
        $publishBox = is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [];

        $input = [
            'article_id' => (int) $article->id,
            'content' => $html,
        ];

        if (is_array($bundle['editor_document'] ?? null)) {
            $input['editor_document'] = $bundle['editor_document'];
        }
        if (isset($bundle['expected_editor_document_hash'])) {
            $input['expected_editor_document_hash'] = (string) $bundle['expected_editor_document_hash'];
        }
        if (isset($bundle['client_rendered_html'])) {
            $input['client_rendered_html'] = (string) $bundle['client_rendered_html'];
        }

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
                $input[$field] = is_int($bundle[$field]) ? $bundle[$field] : (string) $bundle[$field];
            }
        }

        return $input;
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

    /**
     * @param  array<string, mixed>  $bundle
     */
    private function resolveExpectedContentHash(Request $request, array $bundle): ?string
    {
        $hash = $request->input('expected_content_hash', $bundle['expected_content_hash'] ?? null);

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    private function requireUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function errorResponse(ArticleEditorSessionException $exception): JsonResponse
    {
        $payload = [
            'success' => false,
            'error' => $exception->errorCode,
            'message' => $exception->getMessage(),
            ...$exception->context,
        ];

        if ($exception->errorCode === ArticleEditorSessionErrorCode::LOCKED && isset($exception->context['lock'])) {
            $payload['lock'] = $exception->context['lock'];
        }

        return response()->json($payload, $exception->httpStatus);
    }
}
