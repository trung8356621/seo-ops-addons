<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

use Illuminate\Database\Eloquent\Builder;
use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;

final class KeywordTagQuery
{
    /**
     * @param  Builder<Keyword>  $query
     * @param  list<mixed>  $tags
     * @return Builder<Keyword>
     */
    public function apply(Builder $query, array $tags): Builder
    {
        $selected = collect($tags)
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->filter(static fn (string $value): bool => KeywordTag::isKnown($value) || KeywordTag::isGroup($value))
            ->values()
            ->all();

        foreach ($selected as $tag) {
            $query = $this->constrain($query, $tag);
        }

        return $query;
    }

    /**
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    private function constrain(Builder $query, string $tag): Builder
    {
        $groupKey = KeywordTag::parseGroupKey($tag);
        if ($groupKey !== null) {
            return $query->whereHas(
                'ruleGroupMemberships.group',
                static fn (Builder $group): Builder => $group->where('group_key', $groupKey),
            );
        }

        $groupId = KeywordTag::parseGroupId($tag);
        if ($groupId !== null) {
            return $query->whereHasAnyTagId([$groupId]);
        }

        $excludedKinds = [
            KeywordRuleClassifier::KIND_SENTENCE,
            KeywordRuleClassifier::KIND_DESCRIPTIVE_PHRASE,
            KeywordRuleClassifier::KIND_URL_DOMAIN,
            KeywordRuleClassifier::KIND_NOISE,
        ];

        return match ($tag) {
            KeywordTag::SEO_EXCLUDED => $query->whereHas(
                'seoClassification',
                static function (Builder $classification) use ($excludedKinds): void {
                    $classification->where(function (Builder $inner) use ($excludedKinds): void {
                        $inner->where('is_seo_keyword', false)
                            ->orWhere(function (Builder $kinds) use ($excludedKinds): void {
                                $kinds->whereNull('is_seo_keyword')
                                    ->whereIn('phrase_kind', $excludedKinds);
                            });
                    });
                },
            ),
            KeywordTag::ERROR => $query->whereIn('review_status', [
                KeywordReviewStatus::Danger->value,
                KeywordReviewStatus::Warning->value,
            ]),
            KeywordTag::FOCUS => $query->where(function (Builder $outer) use ($excludedKinds): void {
                $outer
                    ->whereDoesntHave('seoClassification')
                    ->orWhereHas(
                        'seoClassification',
                        static function (Builder $classification) use ($excludedKinds): void {
                            $classification->where(function (Builder $inner) use ($excludedKinds): void {
                                $inner->where('is_seo_keyword', true)
                                    ->orWhere(function (Builder $fallback) use ($excludedKinds): void {
                                        $fallback
                                            ->whereNull('is_seo_keyword')
                                            ->where(function (Builder $kind) use ($excludedKinds): void {
                                                $kind->whereNull('phrase_kind')
                                                    ->orWhere('phrase_kind', '')
                                                    ->orWhereNotIn('phrase_kind', $excludedKinds);
                                            });
                                    });
                            });
                        },
                    );
            }),
            KeywordTag::HAS_LINK => $query->whereHas(
                'linkMaps',
                static fn (Builder $maps): Builder => $maps->where('status', '!=', SeoLinkMapStatus::Ignored->value),
            ),
            KeywordTag::WRITING => $query->where(function (Builder $outer): void {
                $unpublishedDraft = static fn (Builder $article): Builder => $article
                    ->where('review_status', ArticleReviewStatus::Draft->value)
                    ->whereDoesntHave(
                        'wordpressLink',
                        static fn (Builder $link): Builder => $link->where('observed_post_status', 'publish'),
                    );
                $taskWriting = static fn (Builder $task): Builder => $task->whereIn('status', ['writing', 'processing']);
                $outer
                    ->whereHas('mainArticles', $unpublishedDraft)
                    ->orWhereHas('linkMaps.sourceArticle', $unpublishedDraft)
                    ->orWhereHas('linkMaps.targetArticle', $unpublishedDraft)
                    ->orWhereHas('mainArticles.projectTasks', $taskWriting)
                    ->orWhereHas('linkMaps.sourceArticle.projectTasks', $taskWriting)
                    ->orWhereHas('linkMaps.targetArticle.projectTasks', $taskWriting);
            }),
            KeywordTag::PENDING_REVIEW => $query->where(function (Builder $outer): void {
                $review = static fn (Builder $article): Builder => $article->where('review_status', ArticleReviewStatus::PendingReview->value);
                $taskReview = static fn (Builder $task): Builder => $task->where('status', 'reviewing');
                $outer
                    ->whereHas('mainArticles', $review)
                    ->orWhereHas('linkMaps.sourceArticle', $review)
                    ->orWhereHas('linkMaps.targetArticle', $review)
                    ->orWhereHas('mainArticles.projectTasks', $taskReview)
                    ->orWhereHas('linkMaps.sourceArticle.projectTasks', $taskReview)
                    ->orWhereHas('linkMaps.targetArticle.projectTasks', $taskReview);
            }),
            KeywordTag::PENDING_PUBLISH => $this->whereRelatedArticle(
                $query,
                static fn (Builder $article): Builder => $article->where('review_status', ArticleReviewStatus::Approved->value)
                    ->where(function (Builder $inner): void {
                        $inner->whereDoesntHave(
                            'wordpressLink',
                            static fn (Builder $link): Builder => $link->where('observed_post_status', 'publish'),
                        )->orWhereHas(
                            'publishingState',
                            static fn (Builder $pub): Builder => $pub->where('published_at', '>', now()),
                        );
                    }),
            ),
            KeywordTag::PUBLISHED => $this->whereRelatedArticle(
                $query,
                static fn (Builder $article): Builder => $article->whereHas(
                    'wordpressLink',
                    static fn (Builder $link): Builder => $link->where('observed_post_status', 'publish'),
                )->whereDoesntHave(
                    'projectTasks',
                    static fn (Builder $task): Builder => $task->whereIn('status', ['writing', 'processing']),
                ),
            ),
            default => $query,
        };
    }

    /**
     * @param  Builder<Keyword>  $query
     * @param  \Closure(Builder): Builder  $articleScope
     * @return Builder<Keyword>
     */
    private function whereRelatedArticle(Builder $query, \Closure $articleScope): Builder
    {
        return $query->where(function (Builder $outer) use ($articleScope): void {
            $outer
                ->whereHas('mainArticles', $articleScope)
                ->orWhereHas('linkMaps.sourceArticle', $articleScope)
                ->orWhereHas('linkMaps.targetArticle', $articleScope);
        });
    }
}
