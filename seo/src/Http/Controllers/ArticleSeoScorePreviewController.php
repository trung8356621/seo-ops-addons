<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Controllers;

use Omnichannel\Addons\Seo\Http\Requests\ArticleSeoScorePreviewRequest;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Live SEO score preview — PHP canonical scorer, no DB write, no WordPress HTTP.
 *
 * POST /api/seo/articles/{article}/seo-score/preview
 */
final class ArticleSeoScorePreviewController extends Controller
{
    public function __invoke(
        ArticleSeoScorePreviewRequest $request,
        SeoArticle $article,
        SeoAnalyzerService $analyzer,
    ): JsonResponse {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $payload = $analyzer->previewScoreContract(
            $article,
            $request->content(),
            $request->title(),
            $request->slug(),
            $request->metaDescription(),
            $request->focusKeyword(),
        );

        return response()->json($payload);
    }
}
