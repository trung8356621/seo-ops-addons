<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoMcpTopicGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoMcpTopicGroupMember;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpClusterTopicalProfileBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpTopicalProfileStaleState;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;
use RuntimeException;

/**
 * Manual MCP Topic Groups — peer members + mask_name compression for Site MCP only.
 * Never mutates cluster_key / keyword membership / canonical / DNA.
 */
final class McpTopicGroupService
{
    public function tablesReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_mcp_topic_group_members')
            && Schema::connection('omi_seo_ai')->hasTable('seo_mcp_topic_groups');
    }

    /**
     * @return list<array{
     *     group_ref: string,
     *     mask_name: string,
     *     mask_name_manual: bool,
     *     member_keys: list<string>
     * }>
     */
    public function groupsForSite(int $siteId): array
    {
        if ($siteId <= 0 || ! $this->tablesReady()) {
            return [];
        }

        $meta = SeoMcpTopicGroup::query()
            ->where('site_id', $siteId)
            ->get(['group_ref', 'mask_name', 'mask_name_manual'])
            ->keyBy(static fn (SeoMcpTopicGroup $g): string => trim((string) $g->group_ref));

        $rows = SeoMcpTopicGroupMember::query()
            ->where('site_id', $siteId)
            ->orderBy('group_ref')
            ->orderBy('cluster_key')
            ->get(['group_ref', 'cluster_key']);

        /** @var array<string, array{group_ref: string, mask_name: string, mask_name_manual: bool, member_keys: list<string>}> $byRef */
        $byRef = [];
        foreach ($rows as $row) {
            $ref = trim((string) $row->group_ref);
            $key = trim((string) $row->cluster_key);
            if ($ref === '' || $key === '') {
                continue;
            }
            if (! isset($byRef[$ref])) {
                $groupMeta = $meta->get($ref);
                $byRef[$ref] = [
                    'group_ref' => $ref,
                    'mask_name' => $groupMeta instanceof SeoMcpTopicGroup
                        ? trim((string) $groupMeta->mask_name)
                        : $ref,
                    'mask_name_manual' => $groupMeta instanceof SeoMcpTopicGroup
                        ? (bool) $groupMeta->mask_name_manual
                        : false,
                    'member_keys' => [],
                ];
            }
            $byRef[$ref]['member_keys'][] = $key;
        }

        $out = [];
        foreach ($byRef as $group) {
            $group['member_keys'] = array_values(array_unique($group['member_keys']));
            if (count($group['member_keys']) < 2) {
                continue;
            }
            if ($group['mask_name'] === '') {
                $group['mask_name'] = $this->suggestMaskNameFromKeys($siteId, $group['member_keys']);
            }
            $out[] = $group;
        }

        return $out;
    }

    /**
     * @return array<string, array{
     *     group_ref: string,
     *     mask_name: string,
     *     member_count: int
     * }>
     */
    public function membershipMapForSite(int $siteId): array
    {
        $out = [];
        foreach ($this->groupsForSite($siteId) as $group) {
            foreach ($group['member_keys'] as $key) {
                $out[$key] = [
                    'group_ref' => $group['group_ref'],
                    'mask_name' => $group['mask_name'],
                    'member_count' => count($group['member_keys']),
                ];
            }
        }

        return $out;
    }

    /**
     * Rename MCP-only mask_name. Does not touch cluster canonical / membership.
     *
     * @return array{group_ref: string, mask_name: string, mask_name_manual: bool}
     */
    public function updateMaskName(int $siteId, string $groupRef, string $maskName): array
    {
        if (! $this->tablesReady()) {
            throw new RuntimeException('mcp_group_table_missing');
        }

        $groupRef = trim($groupRef);
        $maskName = KeywordPhrasePresentation::present(trim(preg_replace('/\s+/u', ' ', $maskName) ?? $maskName));
        if ($siteId <= 0 || $groupRef === '' || $maskName === '') {
            throw new RuntimeException('invalid_mcp_group_input');
        }

        $group = SeoMcpTopicGroup::query()
            ->where('site_id', $siteId)
            ->where('group_ref', $groupRef)
            ->first();
        if (! $group instanceof SeoMcpTopicGroup) {
            throw new RuntimeException('mcp_group_not_found');
        }

        $group->mask_name = $maskName;
        $group->mask_name_manual = true;
        $group->save();

        TopicClusterDirtyState::mark($siteId, 'mcp_group_mask_updated');
        SiteMcpTopicalProfileStaleState::mark($siteId, 'mcp_group_mask_updated');

        return [
            'group_ref' => $groupRef,
            'mask_name' => $maskName,
            'mask_name_manual' => true,
        ];
    }

    /**
     * Create/replace a peer MCP group with mask_name.
     *
     * @param  list<string>  $memberKeys
     * @return array{group_ref: string, mask_name: string, mask_name_manual: bool, member_keys: list<string>}
     */
    public function syncGroup(
        int $siteId,
        array $memberKeys,
        string $maskName,
        bool $maskNameManual = true,
        ?string $existingGroupRef = null,
    ): array {
        if (! $this->tablesReady()) {
            throw new RuntimeException('mcp_group_table_missing');
        }

        $memberKeys = array_values(array_unique(array_filter(array_map(
            static fn (mixed $k): string => trim((string) $k),
            $memberKeys,
        ))));
        $maskName = KeywordPhrasePresentation::present(trim(preg_replace('/\s+/u', ' ', $maskName) ?? $maskName));
        if ($siteId <= 0 || count($memberKeys) < 2 || $maskName === '') {
            throw new RuntimeException('invalid_mcp_group_input');
        }

        $groupRef = trim((string) ($existingGroupRef ?? ''));
        if ($groupRef === '') {
            $groupRef = $this->newGroupRef($siteId);
        }

        DB::connection('omi_seo_ai')->transaction(function () use (
            $siteId,
            $memberKeys,
            $maskName,
            $maskNameManual,
            $groupRef,
        ): void {
            // Detach members from any other group first.
            foreach ($memberKeys as $key) {
                $this->detachMember($siteId, $key, dissolveIfSingleton: true);
            }

            // Drop previous membership rows for this group_ref, then rewrite.
            SeoMcpTopicGroupMember::query()
                ->where('site_id', $siteId)
                ->where('group_ref', $groupRef)
                ->delete();

            SeoMcpTopicGroup::query()->updateOrCreate(
                ['site_id' => $siteId, 'group_ref' => $groupRef],
                [
                    'mask_name' => $maskName,
                    'mask_name_manual' => $maskNameManual,
                ],
            );

            $now = now();
            foreach ($memberKeys as $key) {
                SeoMcpTopicGroupMember::query()->updateOrCreate(
                    ['site_id' => $siteId, 'cluster_key' => $key],
                    [
                        'group_ref' => $groupRef,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }
        });

        TopicClusterDirtyState::mark($siteId, 'mcp_group_updated');
        SiteMcpTopicalProfileStaleState::mark($siteId, 'mcp_group_updated');

        return [
            'group_ref' => $groupRef,
            'mask_name' => $maskName,
            'mask_name_manual' => $maskNameManual,
            'member_keys' => $memberKeys,
        ];
    }

    /**
     * Remove one peer from its group. Auto-dissolve when < 2 members remain.
     * Does not silently rewrite user mask_name.
     */
    public function ungroup(int $siteId, string $clusterKey): void
    {
        if (! $this->tablesReady() || $siteId <= 0) {
            return;
        }
        $clusterKey = trim($clusterKey);
        if ($clusterKey === '') {
            return;
        }

        DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $clusterKey): void {
            $this->detachMember($siteId, $clusterKey, dissolveIfSingleton: true);
        });

        TopicClusterDirtyState::mark($siteId, 'mcp_group_ungrouped');
        SiteMcpTopicalProfileStaleState::mark($siteId, 'mcp_group_ungrouped');
    }

    public function removeCluster(int $siteId, string $clusterKey): void
    {
        $this->ungroup($siteId, $clusterKey);
    }

    public function dissolveGroup(int $siteId, string $groupRefOrClusterKey): void
    {
        if (! $this->tablesReady() || $siteId <= 0) {
            return;
        }

        $key = trim($groupRefOrClusterKey);
        $groupRef = $key;
        $map = $this->membershipMapForSite($siteId);
        if (isset($map[$key])) {
            $groupRef = (string) $map[$key]['group_ref'];
        }

        DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $groupRef): void {
            SeoMcpTopicGroupMember::query()
                ->where('site_id', $siteId)
                ->where('group_ref', $groupRef)
                ->delete();
            SeoMcpTopicGroup::query()
                ->where('site_id', $siteId)
                ->where('group_ref', $groupRef)
                ->delete();
        });

        TopicClusterDirtyState::mark($siteId, 'mcp_group_dissolved');
        SiteMcpTopicalProfileStaleState::mark($siteId, 'mcp_group_dissolved');
    }

    /**
     * Deterministic mask suggestion from peer labels (no AI).
     *
     * @param  list<string>  $labels
     */
    public function suggestMaskName(array $labels): string
    {
        $labels = array_values(array_filter(array_map(
            static function (mixed $label): string {
                $clean = trim(preg_replace('/\s+/u', ' ', (string) $label) ?? (string) $label);

                return KeywordPhrasePresentation::present($clean);
            },
            $labels,
        )));
        if ($labels === []) {
            return '';
        }
        if (count($labels) === 1) {
            return $labels[0];
        }

        $freq = [];
        $tokenized = [];
        foreach ($labels as $label) {
            $words = $this->significantWords($label);
            $tokenized[] = ['label' => $label, 'words' => $words];
            foreach (array_unique($words) as $word) {
                $freq[$word] = ($freq[$word] ?? 0) + 1;
            }
        }

        $best = null;
        $bestScore = -1.0;
        foreach ($tokenized as $row) {
            $words = $row['words'];
            if ($words === []) {
                continue;
            }
            $sum = 0;
            foreach ($words as $word) {
                $sum += (int) ($freq[$word] ?? 0);
            }
            $score = $sum / max(1, count($words));
            // Prefer compact 2–3 word phrases (typical cluster cores).
            $len = count($words);
            if ($len >= 2 && $len <= 3) {
                $score += 0.75;
            } elseif ($len === 1) {
                $score -= 0.5;
            } elseif ($len > 4) {
                $score -= 0.35 * ($len - 4);
            }
            if ($score > $bestScore
                || ($score === $bestScore && mb_strlen($row['label']) < mb_strlen((string) ($best['label'] ?? '')))) {
                $bestScore = $score;
                $best = $row;
            }
        }

        return KeywordPhrasePresentation::present((string) ($best['label'] ?? $labels[0]));
    }

    /**
     * @param  list<string>  $memberKeys
     */
    public function suggestMaskNameFromKeys(int $siteId, array $memberKeys): string
    {
        $labels = [];
        foreach ($this->clusterCards($siteId, $memberKeys) as $card) {
            $labels[] = (string) ($card['label'] ?? '');
        }

        return $this->suggestMaskName($labels);
    }

    /**
     * @param  list<string>  $excludeKeys
     * @return list<array{
     *     cluster_key: string,
     *     label: string,
     *     article_count: int,
     *     internal_link_count: int,
     *     mcp_group_label: string|null
     * }>
     */
    public function searchClusters(int $siteId, string $query, array $excludeKeys = [], int $limit = 12): array
    {
        if ($siteId <= 0) {
            return [];
        }

        $query = trim($query);
        $exclude = array_fill_keys(array_map('strval', $excludeKeys), true);
        $groupMap = $this->membershipMapForSite($siteId);
        $rows = app(KeywordClusterQuery::class)->paginateClusters(
            $siteId,
            ['search' => $query, 'sort' => 'name_asc', 'projection' => 'seo'],
            max(25, $limit * 3),
        )->items();

        $out = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['cluster_key'] ?? ''));
            if ($key === '' || isset($exclude[$key])) {
                continue;
            }
            $group = $groupMap[$key] ?? null;
            $out[] = [
                'cluster_key' => $key,
                'label' => KeywordPhrasePresentation::present((string) ($row['label'] ?? $key)),
                'article_count' => (int) ($row['article_count'] ?? 0),
                'internal_link_count' => (int) ($row['internal_link_count'] ?? 0),
                'mcp_group_label' => is_array($group) ? (string) ($group['mask_name'] ?? '') : null,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $clusterKeys
     * @return list<array{cluster_key: string, label: string, article_count: int, internal_link_count: int}>
     */
    public function clusterCards(int $siteId, array $clusterKeys): array
    {
        $clusterKeys = array_values(array_unique(array_filter(array_map(
            static fn (mixed $k): string => trim((string) $k),
            $clusterKeys,
        ))));
        if ($siteId <= 0 || $clusterKeys === []) {
            return [];
        }

        $query = app(KeywordClusterQuery::class);
        $labels = $query->canonicalLabelsForKeys($siteId, $clusterKeys);
        $byKey = [];
        foreach ($query->paginateClusters($siteId, ['projection' => 'seo'], 500)->items() as $row) {
            $key = trim((string) ($row['cluster_key'] ?? ''));
            if ($key !== '') {
                $byKey[$key] = $row;
            }
        }

        $out = [];
        foreach ($clusterKeys as $key) {
            $row = $byKey[$key] ?? null;
            $label = trim((string) ($labels[$key] ?? ''));
            if ($label === '') {
                $label = is_array($row)
                    ? (string) ($row['label'] ?? $key)
                    : $query->displayLabel($key, '', $siteId);
            }
            $out[] = [
                'cluster_key' => $key,
                'label' => KeywordPhrasePresentation::present($label),
                'article_count' => (int) ($row['article_count'] ?? 0),
                'internal_link_count' => (int) ($row['internal_link_count'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $memberKeys
     * @return array{
     *     ready: bool,
     *     name: string,
     *     article_count: int,
     *     internal_link_count: int,
     *     weight: float,
     *     weight_display: string
     * }
     */
    public function previewDraft(int $siteId, array $memberKeys, string $maskName): array
    {
        $memberKeys = array_values(array_unique(array_filter(array_map(
            static fn (mixed $k): string => trim((string) $k),
            $memberKeys,
        ))));
        $maskName = KeywordPhrasePresentation::present(trim($maskName));
        $empty = [
            'ready' => false,
            'name' => '',
            'article_count' => 0,
            'internal_link_count' => 0,
            'weight' => 0.0,
            'weight_display' => '0',
        ];
        if ($siteId <= 0 || $maskName === '' || count($memberKeys) < 2) {
            return $empty;
        }

        $draftRef = 'draft_'.substr(sha1(implode('|', $memberKeys)), 0, 12);
        $profile = app(SiteMcpClusterTopicalProfileBuilder::class)->build($siteId, [
            'group_ref' => $draftRef,
            'mask_name' => $maskName,
            'member_keys' => $memberKeys,
        ]);

        foreach (is_array($profile['topics'] ?? null) ? $profile['topics'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ref = trim((string) ($row['cluster_ref'] ?? ''));
            if ($ref !== $draftRef) {
                continue;
            }
            $weight = (float) ($row['weight'] ?? 0);
            $weightDisplay = fmod($weight, 1.0) === 0.0
                ? (string) (int) $weight
                : rtrim(rtrim(number_format($weight, 1, '.', ''), '0'), '.');

            return [
                'ready' => true,
                'name' => KeywordPhrasePresentation::present((string) ($row['name'] ?? $maskName)),
                'article_count' => (int) ($row['article_count'] ?? 0),
                'internal_link_count' => count($this->distinctInternalLinkIdsForClusters($siteId, $memberKeys)),
                'weight' => $weight,
                'weight_display' => $weightDisplay,
            ];
        }

        return $empty;
    }

    /**
     * @param  list<string>  $clusterKeys
     * @return list<int>
     */
    public function distinctInternalLinkIdsForClusters(int $siteId, array $clusterKeys): array
    {
        $clusterKeys = array_values(array_filter(array_map(
            static fn (mixed $k): string => trim((string) $k),
            $clusterKeys,
        )));
        if ($clusterKeys === [] || ! Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            return [];
        }

        $keywordIds = [];
        foreach ($clusterKeys as $key) {
            foreach (app(KeywordClusterQuery::class)->memberKeywordIds($siteId, $key) as $id) {
                $keywordIds[(int) $id] = true;
            }
        }
        $ids = array_keys($keywordIds);
        if ($ids === []) {
            return [];
        }

        return DB::connection('omi_seo_ai')->table('seo_link_maps')
            ->whereIn('keyword_id', $ids)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function newGroupRef(int $siteId): string
    {
        do {
            $ref = 'mcp_'.bin2hex(random_bytes(8));
        } while (SeoMcpTopicGroup::query()
            ->where('site_id', $siteId)
            ->where('group_ref', $ref)
            ->exists());

        return $ref;
    }

    private function detachMember(int $siteId, string $clusterKey, bool $dissolveIfSingleton): void
    {
        $row = SeoMcpTopicGroupMember::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->first();
        if (! $row instanceof SeoMcpTopicGroupMember) {
            return;
        }

        $groupRef = trim((string) $row->group_ref);
        $row->delete();
        if ($groupRef === '' || ! $dissolveIfSingleton) {
            return;
        }

        $remaining = SeoMcpTopicGroupMember::query()
            ->where('site_id', $siteId)
            ->where('group_ref', $groupRef)
            ->count();
        if ($remaining < 2) {
            SeoMcpTopicGroupMember::query()
                ->where('site_id', $siteId)
                ->where('group_ref', $groupRef)
                ->delete();
            SeoMcpTopicGroup::query()
                ->where('site_id', $siteId)
                ->where('group_ref', $groupRef)
                ->delete();
        }
    }

    /**
     * @return list<string>
     */
    private function significantWords(string $label): array
    {
        $folded = mb_strtolower($label, 'UTF-8');
        $parts = preg_split('/\s+/u', $folded) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || mb_strlen($part) < 2) {
                continue;
            }
            $out[] = $part;
        }

        return $out;
    }
}
