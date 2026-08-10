<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Http\Controllers;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewAutomationSettingsResolver;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewCreationPolicy;
use Omnichannel\Addons\Commerce\Services\ProductReview\WordPressProductReviewStatusService;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressBusinessSequence;
use Omnichannel\Addons\WordPress\Services\ManualSyncContext;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\ContentProjects\Support\SeoQueueContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Product review status / create / sync for Edit Article.
 */
final class ArticleProductReviewStatusController extends Controller
{
    public function __construct(
        private readonly WordPressProductReviewStatusService $statusService,
        private readonly ArticleWordPressBusinessSequence $sequence,
        private readonly ProductReviewCreationPolicy $policy,
        private readonly ProductReviewAutomationSettingsResolver $reviewSettingsResolver,
    ) {}

    public function status(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return response()->json([
            'success' => true,
            'data' => $this->statusService->statusForArticle(
                $article,
                $this->reviewSettingsResolver->resolve(),
            ),
        ]);
    }

    public function create(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        abort_unless(SeoAccessControl::canMutateInSeoPanel(), 403);

        if (ArticlePostTypeResolver::resolve($article) !== 'product') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ áp dụng cho product.',
                'error_code' => 'not_product',
            ], 422);
        }

        $settings = $this->reviewSettingsResolver->resolve([
            'enabled' => true,
            'target_count' => $request->has('target_count')
                ? max(0, min(50, (int) $request->integer('target_count')))
                : null,
            'block_if_real_reviews_exist' => $request->has('block_if_real_reviews_exist')
                ? $request->boolean('block_if_real_reviews_exist')
                : null,
        ]);

        $result = $this->sequence->runCreate($article, $settings);

        return response()->json([
            'success' => ($result['status'] ?? '') !== 'failed',
            'data' => $result,
            'status' => $this->statusService->statusForArticle($article->fresh() ?? $article, $settings),
        ]);
    }

    public function sync(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        abort_unless(SeoAccessControl::canMutateInSeoPanel(), 403);
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);

        if (ArticlePostTypeResolver::resolve($article) !== 'product') {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ áp dụng cho product.',
                'error_code' => 'not_product',
            ], 422);
        }

        $reviewSettings = $this->reviewSettingsResolver->resolve();

        $userId = (int) ($request->user()?->id ?? 0);
        $manual = ManualSyncContext::make(
            initiatedBy: $userId,
            source: 'article_editor.product_review_sync',
            articleId: (int) $article->id,
            domainId: (int) ($article->site_id ?? 0),
            correlationId: (string) Str::uuid(),
            requestId: (string) Str::uuid(),
        );
        $sideEffect = $manual->toSideEffectContext('manual_product_review_sync');

        $result = SeoQueueContext::runWpSyncFromQueue(
            fn (): array => $this->sequence->runSync($article, $sideEffect, [
                'enabled' => true,
                'retry_failed' => true,
            ]),
        );

        return response()->json([
            'success' => ($result['status'] ?? '') !== 'failed',
            'data' => $result,
            'status' => $this->statusService->statusForArticle(
                $article->fresh() ?? $article,
                $reviewSettings,
            ),
        ]);
    }
}
