<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Controllers;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditorLinksPayloadService;
use Omnichannel\Addons\Content\Services\ArticleEditorSeoPayloadService;
use Omnichannel\Addons\Content\Services\ArticleEditorVocabularyPayloadService;
use Omnichannel\Addons\Media\Services\ArticleEditorMediaAiService;
use Omnichannel\Addons\Media\Services\ArticleEditorSupplementalImagesService;
use Omnichannel\Addons\Content\Services\ArticleFaqEditorService;
use Omnichannel\Addons\Content\Services\ArticleFaqExtractDebugService;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use Omnichannel\Addons\Media\Services\ArticlePostImagesService;
use Omnichannel\Addons\AiPrompt\Services\PromptLoaiSanPhamOptionsService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use Omnichannel\Addons\WordPress\Services\WordPressMediaCapabilityResolver;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use App\Http\Controllers\Controller;
use App\Services\SeoEngineService;
use App\Support\RuntimeLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 2 lazy bootstrap endpoints — everything the initial editor render no
 * longer embeds inline (SEO summary, images, FAQs, meta extras, links, scoring
 * settings, media picker config). Plain HTTP controller (not Livewire) so it can
 * be fetched independently of the editor's own request/response cycle.
 *
 * Routes: see Providers/SeoPanelProvider.php (`api/seo/articles/{article}/editor/*`).
 */
final class ArticleEditorLazyPayloadController extends Controller
{
    public function seoSummary(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return response()->json([
            'success' => true,
            'data' => app(ArticleEditorSeoPayloadService::class)->forEditorSeoSummary($article),
        ]);
    }

    public function images(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return response()->json([
            'success' => true,
            'data' => app(ArticlePostImagesService::class)->resolveForArticle($article),
        ]);
    }

    public function faqs(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $items = app(ArticleFaqEditorService::class)->payloadForArticle($article);
        $faqSnapshot = app(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorFaqSnapshotService::class)
            ->build($article, auth()->user() instanceof \App\Models\User ? auth()->user() : null);

        return response()->json([
            'success' => true,
            'data' => [
                // Contract: never null — empty FAQ is valid empty state.
                'cached' => false,
                'cached_at' => null,
                'items' => $items,
                'count' => count($items),
                'faq_snapshot' => $faqSnapshot,
                'can_generate' => app(SeoCreateArticleSettingsService::class)->getRenewFaqPromptId() !== null,
                // Legacy keys for older clients.
                'faqs' => $items,
                'extract_debug' => app(ArticleFaqExtractDebugService::class)->get($article),
                'can_generate_faq' => app(SeoCreateArticleSettingsService::class)->getRenewFaqPromptId() !== null,
                'can_import_markdown_faq' => SeoAccessControl::canAccessManagerFeatures(),
            ],
            'message' => null,
        ]);
    }

    /**
     * Light FAQ count only — no FAQ rows (shortcode badge / summary).
     */
    public function faqsCount(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $count = (int) $article->faqs()->count();

        return response()->json([
            'success' => true,
            'data' => [
                'count' => $count,
                'can_generate' => app(SeoCreateArticleSettingsService::class)->getRenewFaqPromptId() !== null,
            ],
        ]);
    }

    /**
     * Product gallery / category options / supplemental images — the parts of the
     * old eager `getEditorMetaPayload()` that only the Images/Product panels need.
     */
    public function meta(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $siteId = (int) $article->site_id;
        $supportsProductGallery = $this->supportsProductGallery($article);

        try {
            $productGallery = $supportsProductGallery
                ? app(ArticleMediaLocalService::class)->resolveProductAlbum($article)
                : [];
            $featuredImageUrl = $supportsProductGallery
                ? (string) ($productGallery[0]['url'] ?? '')
                : (string) (app(WordPressArticleContentService::class)->resolveFeaturedImageUrl($article) ?? '');

            $productCategoryOptions = $siteId > 0
                ? app(PromptLoaiSanPhamOptionsService::class)->productCategoryOptionsForSite($siteId)
                : [];

            $supplemental = app(ArticleEditorSupplementalImagesService::class)
                ->forArticle($article, $featuredImageUrl, $productGallery);
        } catch (\Throwable $exception) {
            RuntimeLogger::report($exception, [
                'action' => 'editor.meta',
                'article_id' => (int) $article->id,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'supports_product_gallery' => $supportsProductGallery,
                    'product_category_options' => [],
                    'product_gallery' => [],
                    'supplemental_images' => [],
                    'warning' => 'Không tải đủ meta images — thử lại sau.',
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'supports_product_gallery' => $supportsProductGallery,
                'product_category_options' => collect($productCategoryOptions)
                    ->map(static fn (string $label, int $id): array => ['id' => $id, 'label' => $label])
                    ->values()
                    ->all(),
                'product_gallery' => collect($productGallery)
                    ->map(static fn (array $item): array => [
                        'url' => (string) ($item['url'] ?? ''),
                        'id' => max(0, (int) ($item['id'] ?? 0)),
                    ])
                    ->filter(static fn (array $item): bool => $item['url'] !== '')
                    ->values()
                    ->all(),
                'supplemental_images' => $supplemental,
            ],
        ]);
    }

    public function links(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return response()->json([
            'success' => true,
            'data' => app(ArticleEditorLinksPayloadService::class)->base($article),
        ]);
    }

    public function vocabulary(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return response()->json([
            'success' => true,
            'data' => app(ArticleEditorVocabularyPayloadService::class)->forArticle($article),
        ]);
    }

    public function linksSuggestions(SeoArticle $article, Request $request): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $submitted = $this->submittedEditorContent($request);
        $mode = strtolower(trim((string) $request->input('mode', 'full')));
        $service = app(ArticleEditorLinksPayloadService::class);

        if ($mode === 'fallback') {
            $existing = $request->input('existing_internal', []);
            if (! is_array($existing)) {
                $existing = [];
            }

            return response()->json([
                'success' => true,
                'data' => $service->withFallbackOnly($article, $submitted, $existing),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $service->withSuggestions($article, $submitted),
        ]);
    }

    private function submittedEditorContent(Request $request): ?string
    {
        $content = $request->input('content');
        if (! is_string($content)) {
            return null;
        }

        $content = trim($content);

        return $content !== '' ? $content : null;
    }

    /**
     * Scoring rules / messages — heavy static registries kept out of the initial
     * bootstrap; loaded once alongside (or right after) the SEO summary fetch.
     */
    public function settings(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $promptSettings = app(SeoPromptSettingsService::class);

        return response()->json([
            'success' => true,
            'data' => [
                'seo_scoring_rules' => SeoScoringRulesRegistry::publicRulesForClient(),
                'seo_rule_messages' => SeoScoringRulesRegistry::messagesForLocale(),
                'seo_scoring_messages' => SeoEngineService::scoringMessagesForLocale(),
                'featured_snippet_thresholds' => $promptSettings->getFeaturedSnippetThresholds(),
                'article_length_product' => $promptSettings->resolveArticleLengthTarget('product'),
                'article_length_default' => $promptSettings->resolveArticleLengthTarget('article'),
            ],
        ]);
    }

    public function mediaPromptPreview(SeoArticle $article, Request $request): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $validated = $request->validate([
            'user_brief' => ['required', 'string', 'max:20000'],
            'selection_text' => ['nullable', 'string', 'max:20000'],
            'media_type' => ['nullable', 'string', 'in:image,video'],
            'target' => ['nullable', 'string', 'max:64'],
        ]);

        $userBrief = trim((string) $validated['user_brief']);
        $selectionText = trim((string) ($validated['selection_text'] ?? ''));
        $mediaType = strtolower(trim((string) ($validated['media_type'] ?? 'image')));
        $target = trim((string) ($validated['target'] ?? 'editor'));
        if ($target === '') {
            $target = 'editor';
        }

        $service = app(ArticleEditorMediaAiService::class);
        $payload = $mediaType === 'video'
            ? $service->previewRenderedVideoPrompt($article, $userBrief, $selectionText !== '' ? $selectionText : $userBrief)
            : $service->previewRenderedImagePrompt(
                $article,
                $userBrief,
                $target,
                0,
                '',
                $selectionText !== '' ? $selectionText : $userBrief,
            );

        $rendered = trim((string) ($payload['rendered'] ?? ''));
        $contextLength = mb_strlen($userBrief);
        $renderedLength = mb_strlen($rendered);
        $promptId = (int) ($payload['prompt_id'] ?? 0);

        if ($rendered === '' || $promptId <= 0) {
            return response()->json([
                'success' => false,
                'message' => (string) ($payload['error'] ?? 'Không thể tạo prompt hoàn chỉnh.'),
                'data' => $payload,
            ], 422);
        }

        if ($rendered === $userBrief || ($contextLength > 0 && $renderedLength <= $contextLength)) {
            return response()->json([
                'success' => false,
                'message' => 'Prompt đã merge không hợp lệ — thiếu template Settings.',
                'data' => $payload,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    public function mediaPickerConfig(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $article->loadMissing('site');
        $capability = app(WordPressMediaCapabilityResolver::class)->forSite($article->site);

        return response()->json([
            'success' => true,
            'data' => [
                'articleId' => (int) $article->id,
                'siteId' => (int) $article->site_id,
                'endpoint' => route('seo.articles.media-picker', ['article' => $article->id]),
                // BC alias — site-level WP media library, NOT article wp_post_id.
                'wordPressLinked' => $capability['available'],
                'wordpress_media_available' => $capability['available'],
                'wordpress_media_unavailable_reason' => $capability['reason'],
            ],
        ]);
    }

    private function supportsProductGallery(SeoArticle $article): bool
    {
        $postType = strtolower(trim(SeoProjectTask::normalizePostType(ArticlePostTypeResolver::resolve($article))));
        $isProduct = in_array($postType, ['product', 'e-commerce'], true);

        return $isProduct && ! app(WordPressArticleContentService::class)->isTaxonomyRecord($article);
    }
}
