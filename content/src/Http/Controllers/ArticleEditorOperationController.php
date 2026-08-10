<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Controllers;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionException;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\Media\Services\SeoMediaArticleSlugFixService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ArticleEditorOperationController extends Controller
{
    public function __construct(
        private readonly ArticleWpSyncQueueService $syncQueue,
        private readonly SeoMediaArticleSlugFixService $slugFix,
        private readonly ArticleEditorSessionService $sessions,
    ) {}

    public function status(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $active = $this->syncQueue->activeOperation($article);

        $rawStatus = (string) ($active['raw_status'] ?? '');
        $publicStatus = (string) ($active['status'] ?? '');

        return response()->json([
            'success' => true,
            'article_id' => (int) $article->id,
            'operation' => $active,
            // activeOperation() map pending → queued; so sánh raw + public.
            'has_active_operation' => $active !== null
                && (
                    in_array($rawStatus, [
                        ArticleWpSyncQueueService::STATUS_PENDING,
                        ArticleWpSyncQueueService::STATUS_PROCESSING,
                    ], true)
                    || in_array($publicStatus, ['queued', 'processing'], true)
                ),
        ]);
    }

    /**
     * Batch local media slug fix for article editor "Fix slug all".
     *
     * Canonical flow (save → rename+rewrite → rename map → editor apply → invalidate → save):
     * @see docs/article-editor/image-slug-rename.md
     * Do not add a second rename pipeline in Livewire/JS — use SeoMediaArticleSlugFixService.
     */
    public function fixMediaSlugs(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.seo_media_id' => ['nullable', 'integer', 'min:1'],
            'items.*.url' => ['nullable', 'string', 'max:2048'],
            'items.*.src' => ['nullable', 'string', 'max:2048'],
            'items.*.new_slug' => ['required', 'string', 'regex:/^[a-z0-9\-]+$/i', 'max:200'],
            'items.*.old_slug' => ['nullable', 'string', 'max:200'],
            'editor_session_id' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $sessionId = trim((string) (
            $validated['editor_session_id']
            ?? $request->header('X-Editor-Session-Id')
            ?? ''
        ));

        try {
            // Owning editor session required while a session is active — never treat owner as foreign lock.
            $this->sessions->assertOwningActiveSessionForMediaMutation(
                $article,
                $user,
                $sessionId !== '' ? $sessionId : null,
                'fix_media_slugs',
            );
        } catch (ArticleEditorSessionException $exception) {
            return response()->json([
                'success' => false,
                'error' => $exception->errorCode,
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'lock' => $exception->context['lock'] ?? null,
            ], $exception->httpStatus);
        }

        // Luôn đọc bản article mới nhất (sau save trước Fix slug) — không dùng body stale.
        $article->refresh();

        try {
            $result = $this->slugFix->fixSlugs($article, $validated['items'], [
                'editor_session_id' => $sessionId,
                'user' => $user,
            ]);
        } catch (ArticleEditorSessionException $exception) {
            return response()->json([
                'success' => false,
                'error' => $exception->errorCode,
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'lock' => $exception->context['lock'] ?? null,
            ], $exception->httpStatus);
        }

        $status = ($result['success'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }
}
