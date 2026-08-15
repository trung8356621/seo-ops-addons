<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordRuleGroup;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordRuleGroupMember;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordRuleGroupRule;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordGroupCatalog;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;

final class KeywordGroupMembershipService
{
    public function __construct(
        private readonly KeywordGroupMatcher $matcher,
        private readonly KeywordNormalizer $normalizer,
    ) {}

    public function tablesReady(): bool
    {
        $schema = Schema::connection('omi_seo_ai');

        return $schema->hasTable('seo_keyword_rule_groups')
            && $schema->hasTable('seo_keyword_rule_group_rules')
            && $schema->hasTable('seo_keyword_rule_group_members');
    }

    public function ensureSystemGroups(): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        foreach (KeywordGroupCatalog::systemDefaults() as $index => $def) {
            $group = KeywordRuleGroup::query()->firstOrCreate(
                [
                    'site_id' => 0,
                    'group_key' => $def['key'],
                ],
                [
                    'label' => $def['label'],
                    'group_type' => 'system',
                    'is_active' => true,
                    'sort_order' => $index + 1,
                ],
            );
            if ($group->rules()->exists()) {
                continue;
            }
            foreach ($def['phrases'] as $phrase) {
                $norm = $this->normalizer->normalize($phrase);
                if ($norm['folded_text'] === '') {
                    continue;
                }
                KeywordRuleGroupRule::query()->create([
                    'group_id' => (int) $group->id,
                    'match_type' => 'contains',
                    'phrase' => $norm['raw_text'],
                    'folded_phrase' => $norm['folded_text'],
                    'is_active' => true,
                ]);
            }
        }
    }

    /**
     * @return list<array{id: int, key: string, label: string, rules: list<array<string, string>>}>
     */
    public function activeGroupsPayload(): array
    {
        if (! $this->tablesReady()) {
            return [];
        }

        return KeywordRuleGroup::query()
            ->where('is_active', true)
            ->with(['rules' => static fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->get()
            ->map(static function (KeywordRuleGroup $group): array {
                return [
                    'id' => (int) $group->id,
                    'key' => (string) $group->group_key,
                    'label' => (string) $group->label,
                    'rules' => $group->rules->map(static fn (KeywordRuleGroupRule $rule): array => [
                        'match_type' => (string) $rule->match_type,
                        'phrase' => (string) $rule->phrase,
                        'folded_phrase' => (string) $rule->folded_phrase,
                    ])->all(),
                ];
            })
            ->all();
    }

    public function syncKeyword(int $keywordId, string $phrase, ?array $groups = null): void
    {
        if (! $this->tablesReady() || $keywordId <= 0) {
            return;
        }

        $groups ??= $this->activeGroupsPayload();
        $matchedIds = array_values(array_unique(array_map(
            static fn (array $hit): int => (int) $hit['id'],
            $this->matcher->match($phrase, $groups),
        )));
        $matchedIds = array_values(array_filter($matchedIds, static fn (int $id): bool => $id > 0));

        KeywordRuleGroupMember::query()->where('keyword_id', $keywordId)->delete();
        foreach ($matchedIds as $groupId) {
            KeywordRuleGroupMember::query()->insert([
                'keyword_id' => $keywordId,
                'group_id' => $groupId,
            ]);
        }
    }

    public function recomputeSiteChunk(int $siteId, int $afterId, int $limit): array
    {
        if (! $this->tablesReady()) {
            return ['processed' => 0, 'next_id' => $afterId, 'done' => true];
        }

        $this->ensureSystemGroups();
        $groups = $this->activeGroupsPayload();
        $query = Keyword::query()->where('id', '>', $afterId)->orderBy('id');
        if ($siteId > 0) {
            $query->forSite($siteId);
        }
        $rows = $query->limit($limit)->get(['id', 'phrase']);
        $lastId = $afterId;
        foreach ($rows as $keyword) {
            $lastId = (int) $keyword->id;
            $this->syncKeyword($lastId, (string) $keyword->phrase, $groups);
        }

        return [
            'processed' => $rows->count(),
            'next_id' => $lastId,
            'done' => $rows->count() < $limit,
        ];
    }
}
