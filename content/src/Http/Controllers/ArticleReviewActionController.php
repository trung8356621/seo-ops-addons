<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Controllers;

use Omnichannel\Addons\Content\Http\Requests\ArticleReviewActionRequest;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleReview;
use Omnichannel\Addons\Content\Services\ArticleReviewService;
use Omnichannel\Addons\Content\Exceptions\ArticleReviewException;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST endpoint cho Article Review Action workflow:
 * - GET  /api/seo/articles/{article}/review-actions  — trạng thái + hành động khả dụng + lịch sử.
 * - POST /api/seo/articles/{article}/review-actions  — thực hiện submit_review / approve / archive.
 */
final class ArticleReviewActionController extends Controller
{
    public function __construct(
        private readonly ArticleReviewService $reviewService,
    ) {}

    public function show(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $payload = $this->reviewService->toApiPayload($article, $user);
        $payload['data']['reviews'] = $this->reviewService->history($article)
            ->map(fn (SeoArticleReview $review): array => [
                'id' => (int) $review->id,
                'action_type' => (string) $review->action_type,
                'from_status' => $review->from_status,
                'to_status' => (string) $review->to_status,
                'reviewer_id' => (int) $review->reviewer_id,
                'reviewer_role' => $review->reviewer_role,
                'reviewer_name' => $review->relationLoaded('reviewer') ? $review->reviewer?->name : null,
                'note' => $review->note,
                'created_at' => $review->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json($payload);
    }

    public function store(ArticleReviewActionRequest $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        try {
            $review = $this->reviewService->performAction(
                $article,
                $user,
                $request->actionType(),
                $request->note(),
            );
        } catch (ArticleReviewException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode(),
            ], $exception->httpStatus());
        }

        return response()->json(
            $this->reviewService->toApiPayload($article->fresh() ?? $article, $user, $review),
        );
    }
}
