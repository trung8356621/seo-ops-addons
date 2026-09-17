<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSiteKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;

/**
 * Site-scoped Topic list read model for Filament Topic index.
 *
 * Identity = seo_topics.id (numeric Topic Core), never retired cluster identity.
 */
final class TopicListQuery
{
    public function __construct(
        private readonly TopicLinkedArticleCounter $articleCounter,
    ) {}

    /**
     * @param  array{
     *     search?: string,
     *     sort?: string,
     *     has_articles?: bool,
     *     lock_filter?: string,
     *     per_page?: int,
     *     page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(int $siteId, array $filters = []): LengthAwarePaginator
    {
        if ($siteId <= 0 || ! TopicReclusterService::tablesReady()) {
            return new Paginator([], 0, max(1, (int) ($filters['per_page'] ?? 25)));
        }

        $search = trim((string) ($filters['search'] ?? ''));
        $sort = (string) ($filters['sort'] ?? 'name_asc');
        $hasArticles = (bool) ($filters['has_articles'] ?? false);
        $lockFilter = (string) ($filters['lock_filter'] ?? '');
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));

        $query = SeoTopic::query()
            ->where('site_id', $siteId)
            ->select(['id', 'site_id', 'name', 'status', 'is_locked', 'created_at', 'updated_at']);

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($search)).'%';
            $query->whereRaw('LOWER(name) LIKE ?', [$like]);
        }

        if ($lockFilter === 'topic_locked') {
            $query->where('is_locked', true);
        } elseif ($lockFilter === 'unlocked') {
            $query->where('is_locked', false);
        }

        $topics = $query->orderBy('id')->get();
        if ($topics->isEmpty()) {
            return new Paginator([], 0, $perPage);
        }

        /** @var list<int> $topicIds */
        $topicIds = $topics->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        // Site-wide article counts (same site isolation as TopicLinkedArticleCounter)
        // so Topical Share denominator is the full site, not the search-filtered page.
        $allSiteTopicIds = SeoTopic::query()
            ->where('site_id', $siteId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $siteArticleCounts = $this->articleCounter->countForTopics($siteId, $allSiteTopicIds);
        $shares = (new TopicTopicalShareCalculator)->percentages($siteArticleCounts);

        $memberCounts = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', $topicIds)
            ->selectRaw('topic_id, COUNT(*) as member_count, SUM(CASE WHEN is_locked = 1 THEN 1 ELSE 0 END) as locked_member_count')
            ->groupBy('topic_id')
            ->get()
            ->keyBy(static fn ($row): int => (int) $row->topic_id);

        $rows = [];
        foreach ($topics as $topic) {
            $topicId = (int) $topic->id;
            $counts = $memberCounts->get($topicId);
            $keywordCount = (int) ($counts->member_count ?? 0);
            $lockedMemberCount = (int) ($counts->locked_member_count ?? 0);
            $articleCount = (int) ($siteArticleCounts[$topicId] ?? 0);
            $isTopicLocked = (bool) $topic->is_locked;

            if ($hasArticles && $articleCount <= 0) {
                continue;
            }
            if ($lockFilter === 'membership_locked' && ($isTopicLocked || $lockedMemberCount <= 0)) {
                continue;
            }

            $rows[] = [
                'topic_id' => $topicId,
                'site_id' => $siteId,
                'name' => (string) $topic->name,
                'label' => (string) $topic->name,
                'status' => (string) $topic->status,
                'is_locked' => $isTopicLocked,
                'has_membership_locks' => $lockedMemberCount > 0,
                'locked_member_count' => $lockedMemberCount,
                'keyword_count' => $keywordCount,
                'article_count' => $articleCount,
                'internal_link_count' => $articleCount,
                'internal_links' => $articleCount,
                'intent' => '',
                'coverage' => 'unknown',
                'canonical_source' => $keywordCount > 0 ? 'auto' : 'manual',
                'topical_share' => (float) ($shares[$topicId] ?? 0.0),
                'state' => $keywordCount === 0 ? 'planned' : 'active',
                'updated_at' => $topic->updated_at?->toIso8601String(),
            ];
        }

        $rows = $this->sortRows($rows, $sort);
        $total = count($rows);
        $page = max(1, (int) ($filters['page'] ?? Paginator::resolveCurrentPage()));
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return new Paginator($slice, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
        ]);
    }

    /**
     * @return array{
     *     topic_count: int,
     *     seo_eligible_keywords: int,
     *     assigned: int,
     *     unassigned: int,
     *     topic_locked: int,
     *     membership_locked: int
     * }
     */
    public function summary(int $siteId): array
    {
        if ($siteId <= 0 || ! TopicReclusterService::tablesReady()) {
            return [
                'topic_count' => 0,
                'seo_eligible_keywords' => 0,
                'assigned' => 0,
                'unassigned' => 0,
                'clustered' => 0,
                'unclustered' => 0,
                'topic_locked' => 0,
                'membership_locked' => 0,
            ];
        }

        $topicCount = (int) SeoTopic::query()->where('site_id', $siteId)->count();
        $topicLocked = (int) SeoTopic::query()->where('site_id', $siteId)->where('is_locked', true)->count();
        $seoEligible = (int) SeoSiteKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_seo_keyword', true)
            ->count();
        $assigned = (int) SeoTopicKeyword::query()->where('site_id', $siteId)->count();
        $membershipLocked = (int) SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_locked', true)
            ->count();

        return [
            'topic_count' => $topicCount,
            'seo_eligible_keywords' => $seoEligible,
            'assigned' => $assigned,
            'unassigned' => max(0, $seoEligible - $assigned),
            'clustered' => $assigned,
            'unclustered' => max(0, $seoEligible - $assigned),
            'topic_locked' => $topicLocked,
            'membership_locked' => $membershipLocked,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortRows(array $rows, string $sort): array
    {
        usort($rows, static function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'name_desc' => strcmp((string) $b['name'], (string) $a['name']),
                'keywords_desc' => ((int) $b['keyword_count']) <=> ((int) $a['keyword_count']),
                'keywords_asc' => ((int) $a['keyword_count']) <=> ((int) $b['keyword_count']),
                'articles_desc' => ((int) $b['article_count']) <=> ((int) $a['article_count']),
                'articles_asc' => ((int) $a['article_count']) <=> ((int) $b['article_count']),
                'topical_share_desc' => ((float) $b['topical_share']) <=> ((float) $a['topical_share']),
                'topical_share_asc' => ((float) $a['topical_share']) <=> ((float) $b['topical_share']),
                default => strcmp((string) $a['name'], (string) $b['name']),
            };
        });

        return $rows;
    }
}
