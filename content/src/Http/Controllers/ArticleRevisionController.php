<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Controllers;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleRevision;
use Omnichannel\Addons\Content\Services\SeoArticleRevisionService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ArticleRevisionController extends Controller
{
    public function __construct(
        private readonly SeoArticleRevisionService $revisionService,
    ) {}

    public function index(SeoArticle $article): JsonResponse
    {
        abort_unless($this->canAccessArticle($article), 403);

        $revisions = $this->revisionService->listForArticle((int) $article->id);

        return response()->json([
            'success' => true,
            'count' => $revisions->count(),
            'revisions' => $revisions->map(
                fn (SeoArticleRevision $revision): array => $this->revisionSummary($revision),
            )->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionSummary(SeoArticleRevision $revision): array
    {
        $createdAt = $revision->created_at;

        return [
            'id' => (int) $revision->id,
            'title' => (string) ($revision->title ?? ''),
            'created_at' => $createdAt?->toIso8601String(),
            'created_at_label' => $createdAt !== null
                ? $createdAt->timezone(config('app.timezone'))->format('d/m/Y H:i')
                : '',
            'user_name' => trim((string) ($revision->user?->name ?? '')) !== ''
                ? trim((string) $revision->user->name)
                : 'Hệ thống',
        ];
    }

    private function canAccessArticle(SeoArticle $article): bool
    {
        return SeoAccessControl::canAccessArticle($article);
    }
}
