<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Controllers;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleFaqHtmlRenderer;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class ArticlePreviewController extends Controller
{
    public function __invoke(
        SeoArticle $article,
        ArticleFaqHtmlRenderer $faqRenderer,
        WordPressArticleContentService $wordPressContent,
    ): View|Response|RedirectResponse {
        $article->loadMissing(['site']);

        if ($article->body === null && (int) ($article->wordpressLink?->wp_post_id ?? 0) > 0) {
            $permalink = trim($wordPressContent->resolvePermalink($article));

            if ($permalink !== '') {
                return redirect()->away($permalink);
            }
        }

        $contentHtml = $faqRenderer->renderBodyWithFaqs($article);
        if ($contentHtml === '') {
            $contentHtml = $wordPressContent->resolveEditorHtml($article);
        }

        return view('seo-content-ai::articles.preview', [
            'article' => $article,
            'contentHtml' => $contentHtml,
            'focusKeyword' => app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article),
            'permalink' => $wordPressContent->resolvePermalink($article),
            'editUrl' => ArticleResource::panelUrl('edit', ['record' => $article]),
        ]);
    }
}
