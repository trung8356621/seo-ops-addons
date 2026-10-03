<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Controllers;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleListSeoSummary;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ArticleListSeoDetailsController extends Controller
{
    public function __invoke(SeoArticle $article): JsonResponse
    {
        abort_unless($this->canViewArticle($article), 403);

        $seo = ArticleListSeoSummary::for($article);

        // Return only the fields needed for the dropdown panel
        return response()->json([
            'schema' => $seo['schema'],
            'image_count' => $seo['image_count'],
            'faq_count' => $seo['faq_count'],
            'faq_points' => $seo['faq_points'],
            'featured_snippet_points' => $seo['featured_snippet_points'],
            'links_total' => $seo['links_total'],
            'links_internal' => $seo['links_internal'],
            'links_external' => $seo['links_external'],
        ]);
    }

    private function canViewArticle(SeoArticle $article): bool
    {
        return SeoAccessControl::canAccessArticle($article);
    }
}