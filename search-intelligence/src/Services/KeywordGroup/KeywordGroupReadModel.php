<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordGroupSchema;

final class KeywordGroupReadModel
{
    /**
     * @param  list<array{keyword_id: int, phrase: string}>  $inventory
     * @return array{
     *     groups: list<array<string, mixed>>,
     *     unassigned: list<array{keyword_id: int, phrase: string}>,
     *     unassigned_count: int
     * }
     */
    public function forSite(int $siteId, array $inventory): array
    {
        $unassignedSeed = $this->inventoryRows($inventory);
        if ($siteId <= 0 || ! KeywordGroupSchema::tablesReady()) {
            return [
                'groups' => [],
                'unassigned' => $unassignedSeed,
                'unassigned_count' => count($unassignedSeed),
            ];
        }

        $groups = SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $memberships = SeoKeywordGroupKeyword::query()
            ->where('site_id', $siteId)
            ->orderBy('keyword_id')
            ->get();

        $keywordIds = $memberships
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $phrases = $keywordIds === []
            ? []
            : Keyword::query()->whereIn('id', $keywordIds)->pluck('phrase', 'id')->all();

        $byGroup = $memberships->groupBy(static fn (SeoKeywordGroupKeyword $row): int => (int) $row->group_id);
        $assigned = [];
        $rows = [];
        foreach ($groups as $group) {
            if (! $group instanceof SeoKeywordGroup) {
                continue;
            }
            $members = [];
            foreach ($byGroup->get((int) $group->id, collect()) as $membership) {
                if (! $membership instanceof SeoKeywordGroupKeyword) {
                    continue;
                }
                $keywordId = (int) $membership->keyword_id;
                $assigned[$keywordId] = true;
                $members[] = [
                    'keyword_id' => $keywordId,
                    'phrase' => (string) ($phrases[$keywordId] ?? ''),
                    'source' => (string) $membership->source,
                    'similarity_score' => $membership->similarity_score,
                ];
            }
            $repId = (int) ($group->representative_keyword_id ?? 0);
            $rows[] = [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'source' => (string) $group->source,
                'is_locked' => (bool) $group->is_locked,
                'is_manual' => KeywordGroupSource::isManual($group->source),
                'member_count' => count($members),
                'representative_keyword_id' => $repId > 0 ? $repId : null,
                'representative_phrase' => $repId > 0 ? (string) ($phrases[$repId] ?? '') : '',
                'members' => $members,
            ];
        }

        $unassigned = [];
        foreach ($unassignedSeed as $row) {
            if (isset($assigned[$row['keyword_id']])) {
                continue;
            }
            $unassigned[] = $row;
        }

        return [
            'groups' => $rows,
            'unassigned' => $unassigned,
            'unassigned_count' => count($unassigned),
        ];
    }

    /**
     * @param  list<array{keyword_id: int, phrase: string}>  $inventory
     * @return list<array{keyword_id: int, phrase: string}>
     */
    private function inventoryRows(array $inventory): array
    {
        $out = [];
        foreach ($inventory as $row) {
            $keywordId = (int) ($row['keyword_id'] ?? 0);
            $phrase = trim((string) ($row['phrase'] ?? ''));
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
}
