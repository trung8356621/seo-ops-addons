<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Http\Controllers;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewSource;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\SearchFoundation\Http\Requests\KeywordReviewRequest;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordReviewReasonService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordReviewService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use App\Support\RuntimeLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class KeywordReviewController extends Controller
{
    public function __construct(
        private readonly KeywordReviewService $reviewService,
        private readonly KeywordReviewReasonService $reasonService,
        private readonly KeywordPersistenceService $keywordPersistence,
    ) {}

    public function reasons(Request $request): JsonResponse
    {
        abort_unless(SeoAccessControl::canReviewKeywords(), 403);

        $workspaceId = SeoAccessControl::accountSiteOwnerId();
        $this->reasonService->ensureDefaultReasons($workspaceId, (int) ($request->user()?->id ?? 0));

        $reasons = $this->reasonService->activeReasonsForWorkspace($workspaceId)
            ->map(static fn ($reason): array => [
                'id' => (int) $reason->id,
                'name' => (string) $reason->name,
                'default_severity' => (string) $reason->default_severity,
                'description' => $reason->description,
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'reasons' => $reasons,
            'can_override_severity' => SeoAccessControl::canOverrideKeywordReviewSeverity(),
        ]);
    }

    public function ensureForReview(Request $request): JsonResponse
    {
        abort_unless(SeoAccessControl::canReviewKeywords(), 403);

        $validated = $request->validate([
            'phrase' => ['required', 'string', 'max:255'],
            'site_id' => ['required', 'integer', 'min:1'],
            'target_url' => ['nullable', 'string', 'max:2048'],
            'target_article_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $siteId = (int) $validated['site_id'];
        abort_unless(SeoAccessControl::canAccessSite($siteId), 403);

        try {
            $targetUrl = isset($validated['target_url']) ? trim((string) $validated['target_url']) : '';
            $targetArticleId = (int) ($validated['target_article_id'] ?? 0);

            $keyword = $this->keywordPersistence->upsert(
                (string) $validated['phrase'],
                Keyword::TYPE_SUGGEST,
                $siteId,
                $targetUrl !== '' ? $targetUrl : null,
                targetArticleId: $targetArticleId > 0 ? $targetArticleId : null,
            );

            if (! $keyword instanceof Keyword) {
                return response()->json([
                    'success' => false,
                    'message' => __('seo-content-ai::filament.keyword_review.access_denied'),
                ], 422);
            }

            $this->reviewService->assertKeywordAccessible($keyword);

            return response()->json([
                'success' => true,
                'keyword' => $this->serializeKeyword($keyword),
            ]);
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception);

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function review(KeywordReviewRequest $request, Keyword $keyword): JsonResponse
    {
        abort_unless(SeoAccessControl::canReviewKeywords(), 403);

        try {
            $this->reviewService->assertKeywordAccessible($keyword);

            $articleId = $request->integer('article_id');
            if ($articleId > 0) {
                $article = SeoArticle::query()->find($articleId);
                if ($article instanceof SeoArticle) {
                    $this->reviewService->assertArticleAccessible($article);
                }
            }

            $severity = KeywordReviewStatus::from((string) $request->input('severity'));
            $source = KeywordReviewSource::tryFrom((string) $request->input('source', ''))
                ?? KeywordReviewSource::ArticleSuggestion;

            $reasonId = $request->integer('reason_id');
            $reasonId = $reasonId > 0 ? $reasonId : null;

            $result = $this->reviewService->submitReview(
                $keyword,
                $reasonId,
                $severity,
                $request->input('note'),
                $request->input('custom_reason_text'),
                (int) ($request->user()?->id ?? 0),
                $source,
                $articleId > 0 ? $articleId : null,
                SeoAccessControl::canOverrideKeywordReviewSeverity(),
                $source === KeywordReviewSource::ArticleSuggestion,
            );

            return response()->json([
                'success' => true,
                'keyword' => $this->serializeKeyword($result['keyword']),
            ]);
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception);

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function restore(Request $request, Keyword $keyword): JsonResponse
    {
        abort_unless(SeoAccessControl::canRestoreKeywords(), 403);

        try {
            $this->reviewService->assertKeywordAccessible($keyword);

            $source = KeywordReviewSource::tryFrom((string) $request->input('source', ''))
                ?? KeywordReviewSource::KeywordsTable;

            $restored = $this->reviewService->restoreKeyword(
                $keyword,
                (int) ($request->user()?->id ?? 0),
                $source,
                $request->input('note'),
            );

            return response()->json([
                'success' => true,
                'keyword' => $this->serializeKeyword($restored),
            ]);
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception);

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeKeyword(Keyword $keyword): array
    {
        $keyword->loadMissing('reviewReason');

        return [
            'id' => (int) $keyword->id,
            'phrase' => (string) $keyword->phrase,
            'review_status' => (string) $keyword->review_status,
            'review_reason' => $keyword->reviewReason?->name ?? $keyword->review_note,
            'review_note' => $keyword->review_note,
            'reviewed_at' => optional($keyword->reviewed_at)?->toIso8601String(),
        ];
    }
}
