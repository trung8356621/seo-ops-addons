<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiTopic;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoTopicalLinkSuggestion;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoTopicClusterLink;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Illuminate\Support\Str;
use Throwable;

/**
 * Suggest-only internal linking giữa các node của topical map (pillar/subtopic/
 * cluster_group/faq_group) — KHÔNG ghi/sửa nội dung bài viết. source/target luôn là
 * CLUSTER (topic là nhóm ảo, không map 1-1 vào 1 bài viết) — khớp cột
 * source_cluster_id/target_cluster_id của SeoTopicalLinkSuggestion. TopicalMapBuilder
 * chịu trách nhiệm persist kết quả trả về từ đây.
 */
final class TopicalInternalLinkSuggestionService
{
    private const LOW_CONFIDENCE_THRESHOLD = 0.45;

    private const MAX_SIBLING_LINKS_PER_CLUSTER = 3;

    private const MAX_EXISTING_SOURCES_PER_PILLAR = 3;

    private const MAX_PLANNED_TARGETS_PER_PILLAR = 5;

    /**
     * @param  list<array{
     *   topic_ref: string,
     *   topic_type: string,
     *   parent_ref: string|null,
     *   clusters: list<array{
     *     cluster_id: int,
     *     cluster_ref: string,
     *     name: string,
     *     site_id: int|null,
     *     has_content: bool,
     *     relationship: string,
     *     cluster_type: string,
     *     is_reviewed_only: bool
     *   }>
     * }>  $topicNodes
     * @return list<array{
     *   type: string,
     *   source_cluster_id: int,
     *   target_cluster_id: int,
     *   anchor_text: string,
     *   confidence: float,
     *   priority: float,
     *   reason_codes: list<string>,
     *   fingerprint: string
     * }>
     */
    public function suggestForWorkspace(SeoKeywordWorkspace $workspace, ?int $versionId = null): array
    {
        $topics = SeoKiTopic::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('depth')
            ->orderBy('id')
            ->get();

        $links = SeoTopicClusterLink::query()
            ->whereIn('topic_id', $topics->pluck('id')->all() ?: [0])
            ->with('cluster')
            ->get()
            ->groupBy('topic_id');

        $nodes = [];
        foreach ($topics as $topic) {
            $type = $topic->topic_type instanceof \BackedEnum
                ? $topic->topic_type->value
                : (string) $topic->topic_type;
            $clusters = [];
            foreach ($links->get($topic->id, collect()) as $link) {
                $cluster = $link->cluster;
                if ($cluster === null) {
                    continue;
                }
                $clusters[] = [
                    'cluster_id' => (int) $cluster->id,
                    'cluster_ref' => (string) $cluster->public_ref,
                    'name' => (string) $cluster->name,
                    'site_id' => $cluster->site_id,
                    'has_content' => filled($cluster->target_article_ref),
                    'relationship' => (string) ($link->relationship instanceof \BackedEnum ? $link->relationship->value : $link->relationship),
                    'cluster_type' => (string) ($cluster->cluster_type instanceof \BackedEnum ? $cluster->cluster_type->value : $cluster->cluster_type),
                    'is_reviewed_only' => false,
                ];
            }

            $parentRef = null;
            if ($topic->parent_id) {
                $parent = $topics->firstWhere('id', $topic->parent_id);
                $parentRef = $parent?->public_ref;
            }

            $nodes[] = [
                'topic_ref' => (string) $topic->public_ref,
                'topic_type' => $type,
                'parent_ref' => $parentRef,
                'clusters' => $clusters,
            ];
        }

        $suggestions = $this->suggest($nodes);

        if ($versionId !== null && $suggestions !== []) {
            $this->persistSuggestions($workspace, $versionId, $suggestions);
        }

        return $suggestions;
    }

    /**
     * @param  list<array<string, mixed>>|SeoKeywordWorkspace  $topicNodes
     * @return list<array<string, mixed>>
     */
    public function suggest(array|SeoKeywordWorkspace $topicNodes, ?int $versionId = null): array
    {
        if ($topicNodes instanceof SeoKeywordWorkspace) {
            return $this->suggestForWorkspace($topicNodes, $versionId);
        }

        $byParent = [];
        foreach ($topicNodes as $node) {
            $byParent[$node['parent_ref'] ?? '__root__'][] = $node;
        }

        $suggestions = [];
        $seenFingerprints = [];

        foreach ($topicNodes as $node) {
            if (($node['topic_type'] ?? '') !== 'pillar') {
                continue;
            }

            $primary = $this->findPrimary($node['clusters'] ?? []);
            if ($primary === null) {
                continue;
            }

            $subtreeClusters = $node['clusters'] ?? [];
            foreach ($this->collectDescendants($node, $byParent) as $descendant) {
                array_push($subtreeClusters, ...($descendant['clusters'] ?? []));
            }

            foreach ($subtreeClusters as $cluster) {
                if ((int) $cluster['cluster_id'] === (int) $primary['cluster_id']) {
                    continue;
                }

                $type = match ($cluster['cluster_type']) {
                    'faq' => 'faq_to_parent',
                    'comparison' => 'comparison_to_entity',
                    default => null,
                };

                if ($type !== null) {
                    $this->pushSuggestion($suggestions, $seenFingerprints, $type, $cluster, $primary, 0.7);

                    continue;
                }

                $this->pushSuggestion($suggestions, $seenFingerprints, 'pillar_to_cluster', $primary, $cluster, 0.75);
                $this->pushSuggestion($suggestions, $seenFingerprints, 'cluster_to_pillar', $cluster, $primary, 0.7);
            }

            $this->appendSiblingSuggestions($suggestions, $seenFingerprints, $subtreeClusters);
            $this->appendExistingToPlannedSuggestions($suggestions, $seenFingerprints, $subtreeClusters);
        }

        return $suggestions;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, list<array<string, mixed>>>  $byParent
     * @return list<array<string, mixed>>
     */
    private function collectDescendants(array $node, array $byParent, int $depthGuard = 0): array
    {
        if ($depthGuard > 8) {
            return [];
        }

        $children = $byParent[$node['topic_ref']] ?? [];
        $all = [];
        foreach ($children as $child) {
            $all[] = $child;
            array_push($all, ...$this->collectDescendants($child, $byParent, $depthGuard + 1));
        }

        return $all;
    }

    /**
     * @param  list<array<string, mixed>>  $clusters
     * @return array<string, mixed>|null
     */
    private function findPrimary(array $clusters): ?array
    {
        foreach ($clusters as $cluster) {
            if ($cluster['relationship'] === 'primary') {
                return $cluster;
            }
        }

        return $clusters[0] ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $suggestions
     * @param  array<string, true>  $seenFingerprints
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $target
     */
    private function pushSuggestion(array &$suggestions, array &$seenFingerprints, string $type, array $source, array $target, float $baseConfidence): void
    {
        $sourceId = (int) $source['cluster_id'];
        $targetId = (int) $target['cluster_id'];

        if ($sourceId === $targetId) {
            return;
        }

        if ($source['site_id'] !== null && $target['site_id'] !== null && (int) $source['site_id'] !== (int) $target['site_id']) {
            return;
        }

        $confidence = $baseConfidence;
        if (! empty($source['is_reviewed_only']) || ! empty($target['is_reviewed_only'])) {
            $confidence -= 0.15;
        }

        if ($confidence < self::LOW_CONFIDENCE_THRESHOLD) {
            return;
        }

        $fingerprint = hash('xxh3', implode('|', [$type, $sourceId, $targetId]));
        if (isset($seenFingerprints[$fingerprint])) {
            return;
        }
        $seenFingerprints[$fingerprint] = true;

        $suggestions[] = [
            'type' => $type,
            'source_cluster_id' => $sourceId,
            'target_cluster_id' => $targetId,
            'anchor_text' => $this->normalizeAnchor((string) $target['name']),
            'confidence' => round($confidence, 2),
            'priority' => round($confidence * 100, 2),
            'reason_codes' => [$type],
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $suggestions
     * @param  array<string, true>  $seenFingerprints
     * @param  list<array<string, mixed>>  $clusters
     */
    private function appendSiblingSuggestions(array &$suggestions, array &$seenFingerprints, array $clusters): void
    {
        $count = count($clusters);
        $linksAdded = [];

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $clusters[$i];
                $sourceKey = (int) $a['cluster_id'];

                if (($linksAdded[$sourceKey] ?? 0) >= self::MAX_SIBLING_LINKS_PER_CLUSTER) {
                    continue;
                }

                $this->pushSuggestion($suggestions, $seenFingerprints, 'sibling_related', $a, $clusters[$j], 0.55);
                $linksAdded[$sourceKey] = ($linksAdded[$sourceKey] ?? 0) + 1;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $suggestions
     * @param  array<string, true>  $seenFingerprints
     * @param  list<array<string, mixed>>  $clusters
     */
    private function appendExistingToPlannedSuggestions(array &$suggestions, array &$seenFingerprints, array $clusters): void
    {
        $existing = array_slice(
            array_values(array_filter($clusters, static fn (array $c): bool => ! empty($c['has_content']))),
            0,
            self::MAX_EXISTING_SOURCES_PER_PILLAR,
        );
        $planned = array_slice(
            array_values(array_filter($clusters, static fn (array $c): bool => empty($c['has_content']))),
            0,
            self::MAX_PLANNED_TARGETS_PER_PILLAR,
        );

        foreach ($existing as $source) {
            foreach ($planned as $target) {
                $this->pushSuggestion($suggestions, $seenFingerprints, 'existing_to_planned', $source, $target, 0.5);
            }
        }
    }

    private function normalizeAnchor(string $name): string
    {
        $anchor = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return trim(Str::of($anchor)->limit(80, '')->toString());
    }

    /**
     * @param  list<array<string, mixed>>  $suggestions
     */
    private function persistSuggestions(SeoKeywordWorkspace $workspace, int $versionId, array $suggestions): void
    {
        $max = 500;
        if (function_exists('config')) {
            try {
                $max = max(1, (int) config('seo-content-ai.keyword_intelligence.topical_map.max_link_suggestions', 500));
            } catch (Throwable) {
            }
        }

        foreach (array_slice($suggestions, 0, $max) as $row) {
            $fp = (string) ($row['fingerprint'] ?? hash('sha256', json_encode($row) ?: ''));
            $exists = SeoTopicalLinkSuggestion::query()
                ->where('workspace_id', $workspace->id)
                ->where('fingerprint', $fp)
                ->exists();
            if ($exists) {
                continue;
            }

            $suggestion = new SeoTopicalLinkSuggestion([
                'public_ref' => 'pending',
                'workspace_id' => $workspace->id,
                'tenant_id' => $workspace->tenant_id,
                'site_id' => $workspace->site_id,
                'topical_map_version_id' => $versionId,
                'source_cluster_id' => $row['source_cluster_id'] ?? null,
                'target_cluster_id' => $row['target_cluster_id'] ?? null,
                'relationship' => (string) ($row['type'] ?? 'related'),
                'priority' => $row['priority'] ?? null,
                'confidence' => $row['confidence'] ?? null,
                'reason_codes' => $row['reason_codes'] ?? [],
                'status' => 'suggested',
                'fingerprint' => $fp,
            ]);
            $suggestion->save();
            $suggestion->public_ref = KeywordIntelligencePublicRef::linkSuggestion((int) $suggestion->id);
            $suggestion->save();
        }
    }
}
