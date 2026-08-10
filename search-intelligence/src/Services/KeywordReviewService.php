<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewSource;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordReviewHistory;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordReviewReason;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class KeywordReviewService
{
    public function __construct(
        private readonly KeywordReviewReasonService $reasonService,
    ) {}

    /**
     * @return array{warning: int, danger: int}
     */
    public function reviewStatusCounts(?Builder $baseQuery = null): array
    {
        $query = $baseQuery instanceof Builder ? clone $baseQuery : Keyword::query();

        return [
            'warning' => (clone $query)->where('review_status', KeywordReviewStatus::Warning->value)->count(),
            'danger' => (clone $query)->where('review_status', KeywordReviewStatus::Danger->value)->count(),
        ];
    }

    /**
     * @return array{
     *   keyword: Keyword,
     *   history_id: int
     * }
     */
    public function submitReview(
        Keyword $keyword,
        ?int $reasonId,
        KeywordReviewStatus $severity,
        ?string $note,
        ?string $customReasonText,
        int $reviewedBy,
        KeywordReviewSource $source,
        ?int $articleId = null,
        bool $allowSeverityOverride = true,
        bool $lockRequestedSeverity = false,
    ): array {
        if ($reviewedBy <= 0) {
            throw new InvalidArgumentException('reviewed_by is required.');
        }

        if ($severity === KeywordReviewStatus::Active) {
            throw new InvalidArgumentException(__('seo-content-ai::filament.keyword_review.invalid_severity'));
        }

        $reason = null;
        if ($reasonId !== null && $reasonId > 0) {
            $reason = $this->reasonService->findAccessibleReason($reasonId);
            if (! $reason instanceof KeywordReviewReason || ! $reason->is_active) {
                throw new RuntimeException(__('seo-content-ai::filament.keyword_review.reason_not_found'));
            }

            if (! $lockRequestedSeverity && ! $allowSeverityOverride) {
                $severity = $reason->defaultSeverityEnum();
            }
        } else {
            $customReasonText = $this->nullableTrim($customReasonText);
            if ($customReasonText === null) {
                throw new InvalidArgumentException(__('seo-content-ai::filament.keyword_review.reason_required'));
            }
        }

        if ($articleId !== null && $articleId > 0 && $source !== KeywordReviewSource::ArticleSuggestion) {
            $this->assertKeywordLinkedToArticle($keyword, $articleId);
        }

        return DB::connection('omi_seo_ai')->transaction(function () use (
            $keyword,
            $reason,
            $severity,
            $note,
            $customReasonText,
            $reviewedBy,
            $source,
            $articleId,
        ): array {
            $fromStatus = KeywordReviewStatus::tryFrom((string) $keyword->review_status)
                ?? KeywordReviewStatus::Active;

            $resolvedNote = $reason instanceof KeywordReviewReason
                ? $this->nullableTrim($note)
                : $this->nullableTrim($customReasonText);

            $keyword->forceFill([
                'review_status' => $severity->value,
                'review_reason_id' => $reason instanceof KeywordReviewReason ? (int) $reason->id : null,
                'review_note' => $resolvedNote,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => now(),
            ])->save();

            $history = KeywordReviewHistory::query()->create([
                'keyword_id' => (int) $keyword->id,
                'article_id' => $articleId !== null && $articleId > 0 ? $articleId : null,
                'from_status' => $fromStatus->value,
                'to_status' => $severity->value,
                'reason_id' => $reason instanceof KeywordReviewReason ? (int) $reason->id : null,
                'severity' => $severity->value,
                'note' => $resolvedNote,
                'source' => $source->value,
                'reviewed_by' => $reviewedBy,
                'created_at' => now(),
            ]);

            return [
                'keyword' => $keyword->fresh() ?? $keyword,
                'history_id' => (int) $history->id,
            ];
        });
    }

    public function restoreKeyword(
        Keyword $keyword,
        int $reviewedBy,
        KeywordReviewSource $source,
        ?string $note = null,
    ): Keyword {
        if ($reviewedBy <= 0) {
            throw new InvalidArgumentException('reviewed_by is required.');
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($keyword, $reviewedBy, $source, $note): Keyword {
            $fromStatus = KeywordReviewStatus::tryFrom((string) $keyword->review_status)
                ?? KeywordReviewStatus::Active;

            if ($fromStatus === KeywordReviewStatus::Active) {
                return $keyword;
            }

            $previousReasonId = $keyword->review_reason_id !== null ? (int) $keyword->review_reason_id : null;

            $keyword->forceFill([
                'review_status' => KeywordReviewStatus::Active->value,
                'review_reason_id' => null,
                'review_note' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();

            KeywordReviewHistory::query()->create([
                'keyword_id' => (int) $keyword->id,
                'article_id' => null,
                'from_status' => $fromStatus->value,
                'to_status' => KeywordReviewStatus::Active->value,
                'reason_id' => $previousReasonId,
                'severity' => $fromStatus->value,
                'note' => $this->nullableTrim($note),
                'source' => $source->value,
                'reviewed_by' => $reviewedBy,
                'created_at' => now(),
            ]);

            return $keyword->fresh() ?? $keyword;
        });
    }

    public function assertKeywordAccessible(Keyword $keyword): void
    {
        if (! SeoAccessControl::canAccessPlannerFeatures()) {
            throw new RuntimeException(__('seo-content-ai::filament.keyword_review.access_denied'));
        }

        if (! SeoAccessControl::shouldScopeToAccountOwner()) {
            return;
        }

        $siteIds = SeoAccessControl::accessibleSiteIds();
        if ($siteIds === []) {
            throw new RuntimeException(__('seo-content-ai::filament.keyword_review.access_denied'));
        }

        $hasScope = Keyword::query()
            ->whereKey((int) $keyword->id)
            ->forSites(array_map('intval', $siteIds))
            ->exists();

        if (! $hasScope) {
            throw new RuntimeException(__('seo-content-ai::filament.keyword_review.access_denied'));
        }
    }

    public function assertArticleAccessible(SeoArticle $article): void
    {
        if (! SeoAccessControl::canAccessArticle($article)) {
            throw new RuntimeException(__('seo-content-ai::filament.keyword_review.access_denied'));
        }
    }

    private function assertKeywordLinkedToArticle(Keyword $keyword, int $articleId): void
    {
        $linked = SeoLinkMap::query()
            ->where('keyword_id', (int) $keyword->id)
            ->where('source_article_id', $articleId)
            ->exists();

        if ($linked) {
            return;
        }

        $isFocus = $keyword->mainArticles()->where('articles.id', $articleId)->exists();
        if ($isFocus) {
            return;
        }

        throw new RuntimeException(__('seo-content-ai::filament.keyword_review.keyword_not_linked'));
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
