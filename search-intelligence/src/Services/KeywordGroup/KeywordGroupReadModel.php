<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordGroupSchema;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordUiInventoryQuery;

/**
 * Focused Group read paths — no full-inventory materialization.
 */
final class KeywordGroupReadModel
{
    public const DEFAULT_PER_PAGE = 20;

    public const MEMBER_PAGE_SIZE = 50;

    public const SEARCH_LIMIT = 20;

    /**
     * @param  list<string>|null  $languageVariants
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginateGroups(
        int $siteId,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
        ?array $languageVariants = null,
    ): LengthAwarePaginator {
        unset($languageVariants);
        $perPage = max(1, min(50, $perPage));
        $page = max(1, $page);

        if ($siteId <= 0 || ! KeywordGroupSchema::tablesReady()) {
            return new Paginator([], 0, $perPage, $page);
        }

        $paginator = SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->withCount('memberships')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $repIds = $paginator->getCollection()
            ->pluck('representative_keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $phrases = $repIds === []
            ? []
            : Keyword::query()->whereIn('id', $repIds)->pluck('phrase', 'id')->all();

        $groupIds = $paginator->getCollection()
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $memberPreview = $this->firstMemberBatches($siteId, $groupIds, self::MEMBER_PAGE_SIZE);

        $rows = $paginator->getCollection()->map(function ($group) use ($phrases, $memberPreview): array {
            $groupId = (int) $group->id;
            $repId = (int) ($group->representative_keyword_id ?? 0);
            $preview = $memberPreview[$groupId] ?? ['members' => [], 'has_more' => false];

            return [
                'id' => $groupId,
                'name' => (string) $group->name,
                'source' => (string) $group->source,
                'is_locked' => (bool) $group->is_locked,
                'is_manual' => KeywordGroupSource::isManual($group->source),
                'member_count' => (int) ($group->memberships_count ?? 0),
                'representative_keyword_id' => $repId > 0 ? $repId : null,
                'representative_phrase' => $repId > 0 ? (string) ($phrases[$repId] ?? '') : '',
                'members' => $preview['members'],
                'members_has_more' => $preview['has_more'],
            ];
        });

        $paginator->setCollection($rows);

        return $paginator;
    }

    /**
     * First member batch only for Groups on the current page.
     *
     * @param  list<int>  $groupIds
     * @return array<int, array{members: list<array{keyword_id: int, phrase: string}>, has_more: bool}>
     */
    private function firstMemberBatches(int $siteId, array $groupIds, int $limit): array
    {
        $out = [];
        foreach ($groupIds as $groupId) {
            if ($groupId > 0) {
                $out[$groupId] = ['members' => [], 'has_more' => false];
            }
        }
        if ($out === [] || ! KeywordGroupSchema::tablesReady()) {
            return $out;
        }

        $limit = max(1, min(200, $limit));
        $memberships = SeoKeywordGroupKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('group_id', array_keys($out))
            ->orderBy('group_id')
            ->orderBy('keyword_id')
            ->get(['group_id', 'keyword_id']);

        $counts = [];
        $selectedIds = [];
        foreach ($memberships as $membership) {
            $groupId = (int) $membership->group_id;
            $keywordId = (int) $membership->keyword_id;
            $counts[$groupId] = ($counts[$groupId] ?? 0) + 1;
            if (count($out[$groupId]['members']) >= $limit) {
                continue;
            }
            $out[$groupId]['members'][] = ['keyword_id' => $keywordId, 'phrase' => ''];
            $selectedIds[$keywordId] = $keywordId;
        }

        $phrases = $selectedIds === []
            ? []
            : Keyword::query()->whereIn('id', array_values($selectedIds))->pluck('phrase', 'id')->all();

        foreach ($out as $groupId => $payload) {
            foreach ($payload['members'] as $index => $member) {
                $out[$groupId]['members'][$index]['phrase'] = (string) ($phrases[$member['keyword_id']] ?? '');
            }
            $out[$groupId]['has_more'] = ($counts[$groupId] ?? 0) > $limit;
        }

        return $out;
    }

    /**
     * 1-based page that contains the Group, or null when not found.
     */
    public function pageForGroup(int $siteId, int $groupId, int $perPage = self::DEFAULT_PER_PAGE): ?int
    {
        if ($siteId <= 0 || $groupId <= 0 || ! KeywordGroupSchema::tablesReady()) {
            return null;
        }

        $perPage = max(1, min(50, $perPage));
        $group = SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->whereKey($groupId)
            ->first(['id', 'name']);
        if (! $group instanceof SeoKeywordGroup) {
            return null;
        }

        $position = (int) SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->where(function (Builder $query) use ($group): void {
                $query->where('name', '<', (string) $group->name)
                    ->orWhere(function (Builder $sameName) use ($group): void {
                        $sameName->where('name', (string) $group->name)
                            ->where('id', '<=', (int) $group->id);
                    });
            })
            ->count();

        if ($position <= 0) {
            return null;
        }

        return (int) max(1, (int) ceil($position / $perPage));
    }

    /**
     * @return array{
     *     members: list<array{keyword_id: int, phrase: string}>,
     *     has_more: bool,
     *     total: int
     * }
     */
    public function groupMembers(int $siteId, int $groupId, int $limit = self::MEMBER_PAGE_SIZE, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        if ($siteId <= 0 || $groupId <= 0 || ! KeywordGroupSchema::tablesReady()) {
            return ['members' => [], 'has_more' => false, 'total' => 0];
        }

        $exists = SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->whereKey($groupId)
            ->exists();
        if (! $exists) {
            return ['members' => [], 'has_more' => false, 'total' => 0];
        }

        $total = (int) SeoKeywordGroupKeyword::query()
            ->where('site_id', $siteId)
            ->where('group_id', $groupId)
            ->count();

        $memberships = SeoKeywordGroupKeyword::query()
            ->where('site_id', $siteId)
            ->where('group_id', $groupId)
            ->orderBy('keyword_id')
            ->offset($offset)
            ->limit($limit)
            ->get(['keyword_id']);

        $ids = $memberships->pluck('keyword_id')->map(static fn ($id): int => (int) $id)->all();
        $phrases = $ids === []
            ? []
            : Keyword::query()->whereIn('id', $ids)->pluck('phrase', 'id')->all();

        $members = [];
        foreach ($ids as $keywordId) {
            $members[] = [
                'keyword_id' => $keywordId,
                'phrase' => (string) ($phrases[$keywordId] ?? ''),
            ];
        }

        return [
            'members' => $members,
            'has_more' => ($offset + count($members)) < $total,
            'total' => $total,
        ];
    }

    /**
     * @param  list<string>|null  $languageVariants
     */
    public function unassignedCount(int $siteId, ?array $languageVariants = null): int
    {
        if ($siteId <= 0 || ! KeywordGroupSchema::tablesReady()) {
            return app(KeywordUiInventoryQuery::class)->count($siteId, $languageVariants);
        }

        return (int) $this->unassignedBaseQuery($siteId, $languageVariants)->count();
    }

    /**
     * @param  list<string>|null  $languageVariants
     * @return list<array{keyword_id: int, phrase: string}>
     */
    public function searchUnassigned(
        int $siteId,
        string $query,
        ?array $languageVariants = null,
        int $limit = self::SEARCH_LIMIT,
    ): array {
        $limit = max(1, min(50, $limit));
        $needle = trim($query);
        if ($siteId <= 0 || $needle === '') {
            return [];
        }

        $like = '%'.addcslashes(mb_strtolower($needle, 'UTF-8'), '%_\\').'%';
        $rows = $this->unassignedBaseQuery($siteId, $languageVariants)
            ->whereRaw('LOWER(phrase) LIKE ?', [$like])
            ->orderBy('phrase')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'phrase']);

        $out = [];
        foreach ($rows as $row) {
            $keywordId = (int) $row->id;
            $phrase = trim((string) $row->phrase);
            if ($keywordId <= 0 || $phrase === '') {
                continue;
            }
            $out[] = [
                'keyword_id' => $keywordId,
                'phrase' => $phrase,
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>|null  $languageVariants
     * @return Builder<Keyword>
     */
    private function unassignedBaseQuery(int $siteId, ?array $languageVariants): Builder
    {
        $query = app(KeywordUiInventoryQuery::class)->baseQuery($siteId, $languageVariants);
        if (! KeywordGroupSchema::tablesReady()) {
            return $query;
        }

        $assigned = SeoKeywordGroupKeyword::query()
            ->where('site_id', $siteId)
            ->select('keyword_id');

        return $query->whereNotIn('id', $assigned);
    }
}
