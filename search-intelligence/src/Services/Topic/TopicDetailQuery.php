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
            'state' => $keywordCount === 0 ? 'planned' : 'active',
            'created_at' => $topic->created_at?->toIso8601String(),
            'updated_at' => $topic->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
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

        $phrases = $keywordIds === []
            ? collect()
            : Keyword::query()->whereIn('id', $keywordIds)->pluck('phrase', 'id');

        $siteRows = $keywordIds === []
            ? collect()
            : SeoSiteKeyword::query()
                ->where('site_id', $siteId)
                ->whereIn('keyword_id', $keywordIds)
                ->get(['keyword_id', 'is_seo_keyword', 'seo_intent'])
                ->keyBy(static fn ($row): int => (int) $row->keyword_id);

        $mapped = collect($paginator->items())->map(static function (SeoTopicKeyword $row) use ($phrases, $siteRows): array {
            $keywordId = (int) $row->keyword_id;
            $site = $siteRows->get($keywordId);

            return [
                'id' => $keywordId,
                'keyword_id' => $keywordId,
                'phrase' => (string) ($phrases[$keywordId] ?? ''),
                'source' => (string) $row->source,
                'is_seed' => (bool) $row->is_seed,
                'is_locked' => (bool) $row->is_locked,
                'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                'is_seo_keyword' => (bool) ($site?->is_seo_keyword ?? false),
                'seo_intent' => $site?->seo_intent !== null ? (string) $site->seo_intent : null,
            ];
        })->all();

        return new Paginator(
            $mapped,
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
