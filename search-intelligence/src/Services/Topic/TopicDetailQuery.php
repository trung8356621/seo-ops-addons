<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSiteKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;

/**
 * Site-scoped Topic detail read model.
 *
 * Rejects cross-site topic_id access (returns null → 404 at page layer).
 */
final class TopicDetailQuery
{
    public function __construct(
        private readonly TopicLinkedArticleCounter $articleCounter,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $siteId, int $topicId): ?array
    {
        if ($siteId <= 0 || $topicId <= 0 || ! TopicReclusterService::tablesReady()) {
            return null;
        }

        $topic = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('id', $topicId)
            ->first();
        if (! $topic instanceof SeoTopic) {
            return null;
        }

        $memberships = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->get(['keyword_id', 'source', 'is_seed', 'is_locked', 'confidence']);

        $keywordCount = $memberships->count();
        $lockedMemberCount = $memberships->where('is_locked', true)->count();
        $articleCount = $this->articleCounter->countForTopic($siteId, $topicId);

        $keywordIds = $memberships->pluck('keyword_id')->map(static fn ($id): int => (int) $id)->all();
        $intentCounts = [];
        if ($keywordIds !== []) {
            $intents = SeoSiteKeyword::query()
                ->where('site_id', $siteId)
                ->whereIn('keyword_id', $keywordIds)
                ->whereNotNull('seo_intent')
                ->pluck('seo_intent');
            foreach ($intents as $intent) {
                $key = strtolower(trim((string) $intent));
                if ($key === '') {
                    continue;
                }
                $intentCounts[$key] = (int) ($intentCounts[$key] ?? 0) + 1;
            }
        }

        $primaryIntent = '';
        if ($intentCounts !== []) {
            arsort($intentCounts);
            $primaryIntent = (string) array_key_first($intentCounts);
        }

        return [
            'topic_id' => (int) $topic->id,
            'site_id' => $siteId,
            'name' => (string) $topic->name,
            'label' => (string) $topic->name,
            'status' => (string) $topic->status,
            'is_locked' => (bool) $topic->is_locked,
            'has_membership_locks' => $lockedMemberCount > 0,
            'locked_member_count' => $lockedMemberCount,
            'keyword_count' => $keywordCount,
            'article_count' => $articleCount,
            'internal_link_count' => $articleCount,
            'internal_links' => $articleCount,
            'intent' => $primaryIntent,
            'coverage' => 'unknown',
            'canonical_source' => $keywordCount > 0 ? 'auto' : 'manual',
            'intent_counts' => $intentCounts,
            'last_analyzed' => $topic->updated_at?->toIso8601String(),
            'idea_coverage' => $this->buildIdeaCoverage($siteId, $topicId),
            'state' => $keywordCount === 0 ? 'planned' : 'active',
            'created_at' => $topic->created_at?->toIso8601String(),
            'updated_at' => $topic->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Aggregate seo_topic_keyword_dna for the golden DNA panel shell.
     *
     * @return array{dna_branches: list<array{value: string, keyword_count: int, article_count: int}>}|null
     */
    private function buildIdeaCoverage(int $siteId, int $topicId): ?array
    {
        $rows = SeoTopicKeywordDna::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->get(['keyword_id', 'value']);
        if ($rows->isEmpty()) {
            return null;
        }

        /** @var array<string, array{value: string, keyword_ids: array<int, true>}> $byValue */
        $byValue = [];
        foreach ($rows as $row) {
            $value = trim((string) $row->value);
            if ($value === '') {
                continue;
            }
            $key = mb_strtolower($value);
            if (! isset($byValue[$key])) {
                $byValue[$key] = ['value' => $value, 'keyword_ids' => []];
            }
            $byValue[$key]['keyword_ids'][(int) $row->keyword_id] = true;
        }

        if ($byValue === []) {
            return null;
        }

        $branches = [];
        foreach ($byValue as $item) {
            $branches[] = [
                'value' => $item['value'],
                'keyword_count' => count($item['keyword_ids']),
                'article_count' => 0,
            ];
        }
        usort($branches, static fn (array $a, array $b): int => $b['keyword_count'] <=> $a['keyword_count']);

        return ['dna_branches' => array_slice($branches, 0, 40)];
    }

    /**
     * @return LengthAwarePaginator<int, Keyword>
     */
    public function paginateMembers(int $siteId, int $topicId, int $perPage = 25): LengthAwarePaginator
    {
        if ($siteId <= 0 || $topicId <= 0 || ! TopicReclusterService::tablesReady()) {
            return new Paginator([], 0, max(1, $perPage));
        }

        // Enforce site ownership before listing members.
        $owns = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('id', $topicId)
            ->exists();
        if (! $owns) {
            return new Paginator([], 0, max(1, $perPage));
        }

        $paginator = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->orderByDesc('is_seed')
            ->orderBy('keyword_id')
            ->paginate($perPage);

        $keywordIds = collect($paginator->items())
            ->map(static fn (SeoTopicKeyword $row): int => (int) $row->keyword_id)
            ->all();

        $keywords = $keywordIds === []
            ? collect()
            : Keyword::query()->whereIn('id', $keywordIds)->get()->keyBy('id');

        $ordered = [];
        foreach ($paginator->items() as $row) {
            $keyword = $keywords->get((int) $row->keyword_id);
            if ($keyword instanceof Keyword) {
                $ordered[] = $keyword;
            }
        }

        return new Paginator(
            $ordered,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array<int, list<string>>
     */
    public function dnaDisplayByKeyword(int $siteId, int $topicId, array $keywordIds): array
    {
        if ($siteId <= 0 || $topicId <= 0 || $keywordIds === [] || ! TopicReclusterService::tablesReady()) {
            return [];
        }

        $rows = SeoTopicKeywordDna::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->whereIn('keyword_id', $keywordIds)
            ->orderBy('id')
            ->get(['keyword_id', 'value']);

        /** @var array<int, list<string>> $out */
        $out = [];
        foreach ($rows as $row) {
            $keywordId = (int) $row->keyword_id;
            $value = trim((string) $row->value);
            if ($value === '') {
                continue;
            }
            $out[$keywordId][] = $value;
        }

        return $out;
    }
}
