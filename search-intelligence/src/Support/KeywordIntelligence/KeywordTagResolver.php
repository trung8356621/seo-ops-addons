<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Models\Tag;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;

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
     *     groups?: list<string>
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
        $row = $keyword->seoClassification;
        $classified = $row instanceof SeoKeywordClassification
            && trim((string) ($row->phrase_kind ?? '')) !== '';

        return $this->resolve([
            'classified' => $classified,
            'phrase_kind' => $classified ? (string) $row->phrase_kind : null,
            'is_seo_keyword' => $this->rawSeoFlag($row, $classified),
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

    public function clusterLabel(Keyword $keyword): string
    {
        $row = $keyword->seoClassification;
        $key = trim((string) ($row?->cluster_key ?? ''));
        if ($key === '') {
            return '—';
        }

        $siteId = 0;
        try {
            $siteId = (int) (\Omnichannel\Addons\Seo\Support\SeoAccessControl::globalSiteId() ?? 0);
        } catch (\Throwable) {
            $siteId = 0;
        }

        $label = app(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery::class)
            ->displayLabel($key, '', $siteId > 0 ? $siteId : null);
        $count = $this->clusterCount($key);
        if ($count > 0) {
            return $label.' · '.$count;
        }

        return $label;
    }

    public function clusterKey(Keyword $keyword): string
    {
        return trim((string) ($keyword->seoClassification?->cluster_key ?? ''));
    }

    public function primarySeoTag(array $state): string
    {
        $classified = (bool) ($state['classified'] ?? false);
        $kind = trim((string) ($state['phrase_kind'] ?? ''));
        $excludedKinds = [
            KeywordRuleClassifier::KIND_SENTENCE,
            KeywordRuleClassifier::KIND_DESCRIPTIVE_PHRASE,
            KeywordRuleClassifier::KIND_URL_DOMAIN,
            KeywordRuleClassifier::KIND_NOISE,
        ];
        $isSeo = $state['is_seo_keyword'] ?? null;

        if ($isSeo === false) {
            return KeywordTag::SEO_EXCLUDED;
        }

        if ($classified && $isSeo !== true && in_array($kind, $excludedKinds, true)) {
            return KeywordTag::SEO_EXCLUDED;
        }

        return KeywordTag::FOCUS;
    }

    public function primaryFromClassification(?SeoKeywordClassification $row): string
    {
        $classified = $row instanceof SeoKeywordClassification
            && trim((string) ($row->phrase_kind ?? '')) !== '';

        return $this->primarySeoTag([
            'classified' => $classified,
            'phrase_kind' => $classified ? (string) $row->phrase_kind : null,
            'is_seo_keyword' => $this->rawSeoFlag($row, $classified),
        ]);
    }

    private function rawSeoFlag(?SeoKeywordClassification $row, bool $classified): ?bool
    {
        if (! $row instanceof SeoKeywordClassification) {
            return null;
        }

        if (KeywordClassificationVisibility::hasSeoKeywordColumn() && $row->is_seo_keyword !== null) {
            return (bool) $row->is_seo_keyword;
        }

        return $classified ? KeywordClassificationVisibility::isSeoKeyword($row) : null;
    }

    /**
     * @param  iterable<SeoKeywordClassification>  $rows
     * @return array{focus: int, seo_excluded: int}
     */
    public function countPrimaryTags(iterable $rows, int $unclassified = 0): array
    {
        $counts = [
            KeywordTag::FOCUS => max(0, $unclassified),
            KeywordTag::SEO_EXCLUDED => 0,
        ];
        foreach ($rows as $row) {
            $tag = $this->primaryFromClassification($row instanceof SeoKeywordClassification ? $row : null);
            $counts[$tag] = ($counts[$tag] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array{
     *     classified?: bool,
     *     phrase_kind?: string|null,
     *     is_seo_keyword?: bool|null,
     *     is_ambiguous?: bool,
     *     confidence?: float|null
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
            'cluster' => $this->clusterLabel($keyword),
            'tags_label' => implode(' · ', $labels),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tableEagerLoad(): array
    {
        return [
            'seoClassification',
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

    /** @var array<string, int> */
    private static array $clusterCounts = [];

    private function clusterCount(string $key): int
    {
        if (array_key_exists($key, self::$clusterCounts)) {
            return self::$clusterCounts[$key];
        }
        try {
            self::$clusterCounts[$key] = (int) SeoKeywordClassification::query()
                ->where('cluster_key', $key)
                ->count();
        } catch (\Throwable) {
            self::$clusterCounts[$key] = 0;
        }

        return self::$clusterCounts[$key];
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
