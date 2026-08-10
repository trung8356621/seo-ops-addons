<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Controllers;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditorSeoPayloadService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ArticleSeoPreviewController extends Controller
{
    public function __invoke(SeoArticle $article, ArticleEditorSeoPayloadService $seoPayload): JsonResponse
    {
        abort_unless($this->canViewArticle($article), 403);

        $score = $article->countsTowardSeoScore() && $article->seoProfile?->seo_score !== null
            ? (int) round((float) $article->seoProfile->seo_score)
            : null;

        return response()->json([
            'article' => [
                'id' => $article->id,
                'title' => (string) $article->title,
                'score' => $score,
                'edit_url' => ArticleResource::panelUrl('edit', ['record' => $article]),
            ],
            'seo' => $seoPayload->forArticle($article),
        ]);
    }

    private function canViewArticle(SeoArticle $article): bool
    {
        return SeoAccessControl::canAccessArticle($article);
    }
}
