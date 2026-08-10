<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Http\Controllers;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorMediaMutationService;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorMediaSnapshotService;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionException;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Canonical media snapshot + Featured/Gallery mutations for Article Editor (Phase 2A).
 */
final class ArticleEditorMediaSnapshotController extends Controller
{
    public function __construct(
        private readonly ArticleEditorMediaSnapshotService $snapshots,
        private readonly ArticleEditorMediaMutationService $mutations,
    ) {}

    public function show(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        $user = $request->user();

        $snapshot = $this->snapshots->build(
            $article,
            $user instanceof User ? $user : null,
        );

        return response()->json([
            'success' => true,
            'media_snapshot' => $snapshot,
        ]);
    }

    public function setFeatured(Request $request, SeoArticle $article): JsonResponse
    {
        return $this->mutate($request, $article, function (User $user, ?string $sessionId, $expected) use ($request, $article): array {
            /** @var array<string, mixed> $item */
            $item = is_array($request->input('item')) ? $request->input('item') : [];
            // Merge top-level identity fields — client may send them outside `item`.
            foreach (['url', 'wp_attachment_id', 'seo_media_id', 'id', 'attachment_id', 'media_id', 'alt', 'slug'] as $key) {
                if (! array_key_exists($key, $item) || $item[$key] === null || $item[$key] === '' || $item[$key] === 0) {
                    if ($request->filled($key)) {
                        $item[$key] = $request->input($key);
                    }
                }
            }
            if (! isset($item['url']) || trim((string) $item['url']) === '') {
                $item['url'] = (string) $request->input('url', '');
            }

            return $this->mutations->setFeatured($article, $user, $item, $sessionId, $expected);
        });
    }

    public function clearFeatured(Request $request, SeoArticle $article): JsonResponse
    {
        return $this->mutate($request, $article, function (User $user, ?string $sessionId, $expected) use ($article): array {
            return $this->mutations->clearFeatured($article, $user, $sessionId, $expected);
        });
    }

    public function replaceGallery(Request $request, SeoArticle $article): JsonResponse
    {
        return $this->mutate($request, $article, function (User $user, ?string $sessionId, $expected) use ($request, $article): array {
            $items = $request->input('items', $request->input('gallery', []));
            if (! is_array($items)) {
                $items = [];
            }

            return $this->mutations->replaceGallery($article, $user, $items, $sessionId, $expected);
        });
    }

    public function reorderGallery(Request $request, SeoArticle $article): JsonResponse
    {
        return $this->mutate($request, $article, function (User $user, ?string $sessionId, $expected) use ($request, $article): array {
            $ids = $request->input('ordered_ids', $request->input('ids', []));
            if (! is_array($ids)) {
                $ids = [];
            }

            return $this->mutations->reorderGallery(
                $article,
                $user,
                array_map(static fn ($id): string => (string) $id, $ids),
                $sessionId,
                $expected,
            );
        });
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
                'media_snapshot' => $snapshot,
            ]);
        } catch (ArticleEditorSessionException $exception) {
            return response()->json([
                'success' => false,
                'error' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'lock' => $exception->context['lock'] ?? null,
                'conflict' => $exception->context,
            ], $exception->httpStatus);
        }
    }
}
