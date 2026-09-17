<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Models\Tag;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\HideKeywordFromSeoService;

final class KeywordTagResolver
{
    /** @var array<int, string>|null */
    private static ?array $groupNameById = null;

    /**
     * @param  array{
     *     classified?: bool,
     *     phrase_kind?: string|null,
     *     is_seo_keyword?: bool|null,
     *     is_ambiguous?: bool,
     *     confidence?: float|null,
     *     internal_link_count?: int,
     *     workflow?: string|null,
     *     manual_error?: bool,
     *     groups?: list<string>,
     *     seo_hidden?: bool
     * }  $state
     * @return list<string>
     */
    public function resolve(array $state): array
    {
        $tags = [];
        $primary = $this->primarySeoTag($state);
        $tags[] = $primary;

        $manualError = (bool) ($state['manual_error'] ?? false);
        if ($manualError && $primary !== KeywordTag::SEO_EXCLUDED) {
            $tags[] = KeywordTag::ERROR;
        }

        if ((int) ($state['internal_link_count'] ?? 0) > 0) {
            $tags[] = KeywordTag::HAS_LINK;
        }
        $workflow = $this->canonicalWorkflow((string) ($state['workflow'] ?? ''));
        if ($workflow !== null) {
            $tags[] = $workflow;
        }

        foreach ($state['groups'] ?? [] as $group) {
            $code = trim((string) $group);
            if ($code === '' || in_array($code, $tags, true)) {
                continue;
            }
            $tags[] = $code;
        }

        return $tags;
    }

    /**
     * @return list<string>
     */
    public function forKeyword(Keyword $keyword): array
    {
        $hidden = app(HideKeywordFromSeoService::class)->isHidden((int) $keyword->id);

        return $this->resolve([
            'seo_hidden' => $hidden,
            'internal_link_count' => (int) ($keyword->site_links_count ?? 0),
            'workflow' => $this->workflowFromKeyword($keyword),
            'manual_error' => $keyword->isManualError(),
            'groups' => $this->groupCodes($keyword),
        ]);
    }

    /**
     * @return list<array{code: string, label: string, badge_class: string}>
     */
    public function displayTags(Keyword $keyword): array
    {
        $items = [];
        foreach ($this->forKeyword($keyword) as $code) {
            if (KeywordTag::isKnown($code)) {
                $items[] = [
                    'code' => $code,
                    'label' => KeywordTag::label($code),
                    'badge_class' => KeywordTag::badgeClass($code),
                ];

                continue;
            }

            $groupKey = KeywordTag::parseGroupKey($code);
            if ($groupKey !== null) {
                continue;
            }

            $groupId = KeywordTag::parseGroupId($code);
            if ($groupId === null) {
                continue;
            }
            $label = $this->groupName($groupId);
            if ($label === '') {
                continue;
            }
            $items[] = [
                'code' => $code,
                'label' => $label,
                'badge_class' => KeywordTag::badgeClass($code),
            ];
        }

        return $items;
    }

    public function primarySeoTag(array $state): string
    {
        if (($state['seo_hidden'] ?? false) === true || ($state['is_seo_keyword'] ?? null) === false) {
            return KeywordTag::SEO_EXCLUDED;
        }

        return KeywordTag::FOCUS;
    }

    /**
     * @param  array{
     *     classified?: bool,
     *     phrase_kind?: string|null,
     *     is_seo_keyword?: bool|null,
     *     is_ambiguous?: bool,
     *     confidence?: float|null,
     *     seo_hidden?: bool
     * }  $state
     */
    public function allowsAiGeneration(array $state): bool
    {
        return $this->primarySeoTag($state) === KeywordTag::FOCUS;
    }

    /**
     * @return array{phrase: string, tags: list<string>, cluster: string, tags_label: string}
     */
    public function mcpItem(Keyword $keyword): array
    {
        $display = $this->displayTags($keyword);
        $labels = array_map(static fn (array $item): string => $item['label'], $display);

        return [
            'phrase' => (string) $keyword->phrase,
            'tags' => array_map(static fn (array $item): string => $item['code'], $display),
            'cluster' => '—',
            'tags_label' => implode(' · ', $labels),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tableEagerLoad(): array
    {
        return [
            'metas',
            'mainArticles.site',
            'mainArticles.wordpressLink',
            'mainArticles.publishingState',
            'mainArticles.projectTasks',
            'linkMaps' => static fn ($mapQuery): mixed => $mapQuery
                ->orderBy('id')
                ->with([
                    'sourceArticle:id,site_id,title,slug,review_status',
                    'targetArticle:id,site_id,title,slug,review_status',
                    'sourceArticle.wordpressLink',
                    'sourceArticle.publishingState',
                    'sourceArticle.projectTasks',
                    'targetArticle.wordpressLink',
                    'targetArticle.publishingState',
                    'targetArticle.projectTasks',
                ]),
        ];
    }

    public function workflowFromKeyword(Keyword $keyword): ?string
    {
        $articles = $this->relatedArticles($keyword);
        $hasWriting = false;
        $hasReview = false;
        $hasScheduled = false;
        $hasPublished = false;

        foreach ($articles as $article) {
            $review = ArticleReviewStatus::tryFromString((string) ($article->review_status ?? ''));
            $observed = strtolower(trim((string) ($article->wordpressLink?->observed_post_status ?? '')));
            $publication = strtolower(trim((string) ($article->publishingState?->publication_status ?? '')));
            $pubAt = $article->publishingState?->published_at ?? null;
            $isPublished = $observed === 'publish' || $publication === 'publish' || $publication === 'published';
            $hasActiveRewrite = false;

            foreach ($article->projectTasks ?? [] as $task) {
                $taskStatus = strtolower(trim((string) ($task->status ?? '')));
                if (in_array($taskStatus, ['writing', 'processing'], true)) {
                    $hasActiveRewrite = true;
                    $hasWriting = true;
                }
                if ($taskStatus === 'draft' && ! $isPublished) {
                    $hasWriting = true;
                }
                if ($taskStatus === 'reviewing') {
                    $hasReview = true;
                }
                $scheduled = $task->scheduled_publish_at ?? null;
                if ($scheduled instanceof \DateTimeInterface && $scheduled > now()) {
                    $hasScheduled = true;
                }
            }
            if ($review === ArticleReviewStatus::Draft && ! $isPublished) {
                $hasWriting = true;
            }
            if ($review === ArticleReviewStatus::PendingReview) {
                $hasReview = true;
            }
            if ($hasActiveRewrite) {
                $hasWriting = true;
            }
            if ($isPublished && ! $hasActiveRewrite) {
                $hasPublished = true;
            }
            if ($review === ArticleReviewStatus::Approved && ! $isPublished) {
                $hasScheduled = true;
            }
            if ($pubAt instanceof \DateTimeInterface && $pubAt > now()) {
                $hasScheduled = true;
            }
            if (in_array($publication, ['scheduled', 'queued', 'pending_publish'], true)) {
                $hasScheduled = true;
            }
        }

        if ($hasWriting) {
            return KeywordTag::WRITING;
        }
        if ($hasReview) {
            return KeywordTag::PENDING_REVIEW;
        }
        if ($hasScheduled) {
            return KeywordTag::PENDING_PUBLISH;
        }
        if ($hasPublished) {
            return KeywordTag::PUBLISHED;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function groupCodes(Keyword $keyword): array
    {
        unset($keyword);

        return [];
    }

    private function groupName(int $tagId): string
    {
        if (self::$groupNameById === null) {
            try {
                self::$groupNameById = Tag::query()
                    ->get(['id', 'name'])
                    ->mapWithKeys(static fn (Tag $tag): array => [(int) $tag->id => trim((string) $tag->name)])
                    ->all();
            } catch (\Throwable) {
                self::$groupNameById = [];
            }
        }

        return trim((string) (self::$groupNameById[$tagId] ?? ''));
    }

    /**
     * @return list<SeoArticle>
     */
    private function relatedArticles(Keyword $keyword): array
    {
        $found = [];
        if ($keyword->relationLoaded('mainArticles')) {
            foreach ($keyword->mainArticles as $article) {
                if ($article instanceof SeoArticle) {
                    $found[$article->getKey()] = $article;
                }
            }
        }
        if ($keyword->relationLoaded('linkMaps')) {
            foreach ($keyword->linkMaps as $map) {
                if (! $map instanceof SeoLinkMap) {
                    continue;
                }
                foreach ([$map->sourceArticle ?? null, $map->targetArticle ?? null] as $article) {
                    if ($article instanceof SeoArticle) {
                        $found[$article->getKey()] = $article;
                    }
                }
            }
        }

        return array_values($found);
    }

    private function canonicalWorkflow(string $workflow): ?string
    {
        return match ($workflow) {
            KeywordTag::WRITING, KeywordTag::PENDING_REVIEW, KeywordTag::PENDING_PUBLISH, KeywordTag::PUBLISHED => $workflow,
            default => null,
        };
    }
}
