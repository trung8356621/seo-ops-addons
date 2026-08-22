<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Controllers;

use Omnichannel\Addons\Seo\Exceptions\FaqManualExtractException;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorFaqMutationService;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorFaqSnapshotService;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionException;
use Omnichannel\Addons\Content\Services\ArticleFaqGeneratorService;
use Omnichannel\Addons\Content\Services\ArticleFaqManualExtractService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Canonical FAQ snapshot + mutations for Article Editor (Phase 2C).
 */
final class ArticleEditorFaqSnapshotController extends Controller
{
    public function __construct(
        private readonly ArticleEditorFaqSnapshotService $snapshots,
        private readonly ArticleEditorFaqMutationService $mutations,
        private readonly ArticleFaqGeneratorService $generator,
        private readonly ArticleFaqManualExtractService $manualExtract,
    ) {}

    public function show(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        $user = $request->user();

        return response()->json([
            'success' => true,
            'faq_snapshot' => $this->snapshots->build(
                $article,
                $user instanceof User ? $user : null,
            ),
        ]);
    }

    public function replace(Request $request, SeoArticle $article): JsonResponse
    {
        return $this->mutate($request, $article, function (User $user, ?string $sessionId, $expected) use ($request, $article): array {
            $items = $request->input('items', $request->input('faqs', []));
            if (! is_array($items)) {
                $items = [];
            }

            return $this->mutations->replaceSnapshot($article, $user, $items, $sessionId, $expected);
        });
    }

    public function generatePreview(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        abort_unless(SeoAccessControl::canAccessManagerFeatures(), 403);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $lockKey = 'article-faq-generate-preview:'.(int) $article->getKey();
        $lock = Cache::lock($lockKey, 180);
        if (! $lock->get()) {
            return response()->json([
                'success' => false,
                'error' => 'faq_generation_in_flight',
                'message' => 'FAQ generation already in progress.',
            ], 409);
        }

        try {
            app(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService::class)
                ->assertArticleEditable($article);

            $html = trim((string) $request->input('editor_html', ''));
            $preview = $this->generator->generatePreview($article, $html);

            return response()->json([
                'success' => true,
                'preview' => true,
                'faq_count' => $preview['faq_count'],
                'faqs' => $preview['faqs'],
            ]);
        } catch (ArticleEditorSessionException $exception) {
            return $this->sessionError($exception);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'error' => 'faq_generation_failed',
                'message' => $exception->getMessage(),
            ], 422);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Editor extract path (Phase 6C.2) — canonical REST; Livewire extract remains for non-editor callers.
     */
    public function extract(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $html = trim((string) $request->input('html', ''));
        $articleHtml = trim((string) $request->input('article_html', ''));
        $sessionId = trim((string) ($request->input('editor_session_id')
            ?: $request->header('X-Editor-Session-Id')
            ?: ''));
        $expectedDoc = $request->input('expected_document_version');

        try {
            $result = $this->manualExtract->extractFromHtmlFragment(
                $article,
                $html,
                $articleHtml,
                $user,
                $sessionId !== '' ? $sessionId : null,
                $expectedDoc,
            );
            $article->refresh();
            $snapshot = $this->snapshots->build($article, $user);

            return response()->json([
                'success' => true,
                'faqs' => $result['faqs'] ?? [],
                'editor_html' => (string) ($result['editor_html'] ?? ''),
                'faq_snapshot' => $snapshot,
                'document_version' => max(1, (int) ($article->document_version ?? 1)),
            ]);
        } catch (ArticleEditorSessionException $exception) {
            return $this->sessionError($exception);
        } catch (FaqManualExtractException $exception) {
            return response()->json([
                'success' => false,
                'error' => 'faq_extract_failed',
                'message' => $exception->getMessage(),
                'debug' => $exception->debug ?? null,
            ], 422);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'error' => 'faq_extract_failed',
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function apply(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $sessionId = trim((string) ($request->input('editor_session_id')
            ?: $request->header('X-Editor-Session-Id')
            ?: ''));
        $expectedSnap = $request->input('expected_snapshot_version');
        $expectedDoc = $request->input('expected_document_version');
        $items = $request->input('items', $request->input('faqs', []));
        if (! is_array($items)) {
            $items = [];
        }
        $html = trim((string) $request->input('editor_html', ''));

        try {
            $result = $this->mutations->applyToDocument(
                $article,
                $user,
                $items,
                $html,
                $sessionId !== '' ? $sessionId : null,
                $expectedDoc,
                $expectedSnap,
            );
            $article->refresh();

            return response()->json([
                'success' => true,
                'faq_snapshot' => $result['faq_snapshot'],
                'editor_html' => $result['editor_html'],
                'document_version' => max(1, (int) ($article->document_version ?? 1)),
            ]);
        } catch (ArticleEditorSessionException $exception) {
            return $this->sessionError($exception);
        }
    }

    /**
     * @param  callable(User, ?string, int|string|null): array<string, mixed>  $action
     */
    private function mutate(Request $request, SeoArticle $article, callable $action): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $sessionId = trim((string) ($request->input('editor_session_id')
            ?: $request->header('X-Editor-Session-Id')
            ?: ''));
        $expected = $request->input('expected_snapshot_version');

        try {
            $snapshot = $action($user, $sessionId !== '' ? $sessionId : null, $expected);

            return response()->json([
                'success' => true,
                'faq_snapshot' => $snapshot,
            ]);
        } catch (ArticleEditorSessionException $exception) {
            return $this->sessionError($exception);
        }
    }

    private function sessionError(ArticleEditorSessionException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => $exception->errorCode,
            'message' => $exception->getMessage(),
            'lock' => $exception->context['lock'] ?? null,
            'conflict' => $exception->context,
        ], $exception->httpStatus);
    }
}
