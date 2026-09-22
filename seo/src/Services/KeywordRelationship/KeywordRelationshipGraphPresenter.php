<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\KeywordRelationship;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordRelationship;

/**
 * Maps KeywordRelationship DTO → Apache ECharts graph option (UI only).
 * Never embeds ECharts config into MCP schema.
 */
final class KeywordRelationshipGraphPresenter
{
    /**
     * @return array{
     *   nodes: list<array<string, mixed>>,
     *   edges: list<array<string, mixed>>,
     *   categories: list<array{name: string}>,
     *   truncations: array<string, mixed>,
     *   side_panels: array<string, array<string, mixed>>,
     *   filters: array<string, bool>
     * }
     */
    public function present(KeywordRelationship $relationship, array $enabledCategories = []): array
    {
        $defaults = [
            'topic' => true,
            'article' => true,
            'dna' => true,
            'related_keyword' => true,
            'gsc' => false,
            'internal_link' => false,
            'planning' => false,
        ];
        $filters = array_merge($defaults, $enabledCategories);

        $payload = $relationship->toArray();
        $nodes = [];
        $edges = [];
        $sidePanels = [];
        $categories = [];
        $catIndex = [];

        $ensureCat = static function (string $name) use (&$categories, &$catIndex): int {
            if (! isset($catIndex[$name])) {
                $catIndex[$name] = count($categories);
                $categories[] = ['name' => $name];
            }

            return $catIndex[$name];
        };

        $kw = is_array($payload['keyword'] ?? null) ? $payload['keyword'] : [];
        $kwId = (int) ($kw['id'] ?? 0);
        $kwNodeId = 'keyword:'.$kwId;
        $nodes[] = [
            'id' => $kwNodeId,
            'name' => (string) ($kw['phrase'] ?? 'Keyword'),
            'category' => $ensureCat('Keyword'),
            'symbolSize' => 56,
            'draggable' => false,
            'kind' => 'keyword',
        ];
        $sidePanels[$kwNodeId] = $kw;

        if ($filters['topic']) {
            foreach ((array) ($payload['topics'] ?? []) as $topic) {
                if (! is_array($topic)) {
                    continue;
                }
                $tid = 'topic:'.(int) ($topic['id'] ?? 0);
                $nodes[] = [
                    'id' => $tid,
                    'name' => (string) ($topic['name'] ?? 'Topic'),
                    'category' => $ensureCat('Topic'),
                    'symbolSize' => 40,
                    'kind' => 'topic',
                ];
                $edges[] = ['source' => $kwNodeId, 'target' => $tid, 'label' => ['show' => true, 'formatter' => 'member_of']];
                $sidePanels[$tid] = $topic;
            }
        }

        if ($filters['article']) {
            foreach ((array) ($payload['focus_articles'] ?? []) as $article) {
                if (! is_array($article)) {
                    continue;
                }
                $aid = 'article:'.(int) ($article['article_id'] ?? 0);
                $nodes[] = [
                    'id' => $aid,
                    'name' => (string) ($article['title'] ?? 'Article'),
                    'category' => $ensureCat('Focus Article'),
                    'symbolSize' => 36,
                    'kind' => 'article',
                ];
                $edges[] = ['source' => $kwNodeId, 'target' => $aid, 'label' => ['show' => true, 'formatter' => 'focus_article']];
                $sidePanels[$aid] = $article;
            }
        }

        if ($filters['dna']) {
            $dnaItems = is_array($payload['dna']['topic_dna'] ?? null) ? $payload['dna']['topic_dna'] : [];
            $topicId = (int) (($payload['topics'][0]['id'] ?? 0));
            foreach ($dnaItems as $i => $dna) {
                if (! is_array($dna)) {
                    continue;
                }
                $did = 'dna:'.$topicId.':'.$i;
                $nodes[] = [
                    'id' => $did,
                    'name' => (string) ($dna['phrase'] ?? 'DNA'),
                    'category' => $ensureCat('DNA'),
                    'symbolSize' => 22,
                    'kind' => 'dna',
                ];
                $source = $topicId > 0 ? 'topic:'.$topicId : $kwNodeId;
                $edges[] = ['source' => $source, 'target' => $did, 'label' => ['show' => true, 'formatter' => 'has_dna']];
                $sidePanels[$did] = $dna;
            }
        }

        if ($filters['related_keyword']) {
            $related = is_array($payload['related_keywords']['items'] ?? null) ? $payload['related_keywords']['items'] : [];
            foreach ($related as $rel) {
                if (! is_array($rel)) {
                    continue;
                }
                $rid = (string) ($rel['keyword_ref'] ?? ('keyword:'.(int) ($rel['id'] ?? 0)));
                $nodes[] = [
                    'id' => $rid,
                    'name' => (string) ($rel['phrase'] ?? 'Related'),
                    'category' => $ensureCat('Related Keyword'),
                    'symbolSize' => 24,
                    'kind' => 'related_keyword',
                ];
                $edges[] = [
                    'source' => $kwNodeId,
                    'target' => $rid,
                    'label' => ['show' => true, 'formatter' => (string) ($rel['relation_type'] ?? 'same_topic')],
                ];
                $sidePanels[$rid] = $rel;
            }
        }

        if ($filters['gsc'] && ($payload['gsc']['available'] ?? false) === true) {
            $mappings = is_array($payload['gsc']['query_mappings']['items'] ?? null)
                ? $payload['gsc']['query_mappings']['items']
                : [];
            foreach ($mappings as $map) {
                if (! is_array($map)) {
                    continue;
                }
                $mid = (string) ($map['mapping_ref'] ?? ('gsc:'.(int) ($map['id'] ?? 0)));
                $nodes[] = [
                    'id' => $mid,
                    'name' => (string) (
                        (($map['sample_query'] ?? '') !== ''
                            ? $map['sample_query']
                            : ($map['normalized_query'] ?? 'GSC'))
                    ),
                    'category' => $ensureCat('GSC Query'),
                    'symbolSize' => 20,
                    'kind' => 'gsc',
                ];
                $edges[] = ['source' => $kwNodeId, 'target' => $mid, 'label' => ['show' => true, 'formatter' => 'gsc_mapping']];
                $sidePanels[$mid] = $map;
            }
        }

        if ($filters['internal_link'] && ($payload['internal_links']['available'] ?? false) === true) {
            foreach (['inbound', 'outbound'] as $dir) {
                $items = is_array($payload['internal_links'][$dir]['items'] ?? null)
                    ? $payload['internal_links'][$dir]['items']
                    : [];
                foreach ($items as $link) {
                    if (! is_array($link)) {
                        continue;
                    }
                    $lid = 'link:'.(int) ($link['link_map_id'] ?? 0);
                    $label = (string) ($link['anchor_text'] ?: ('Link #'.(int) ($link['link_map_id'] ?? 0)));
                    $nodes[] = [
                        'id' => $lid,
                        'name' => $label,
                        'category' => $ensureCat('Internal Link'),
                        'symbolSize' => 18,
                        'kind' => 'internal_link',
                    ];
                    $edges[] = [
                        'source' => $kwNodeId,
                        'target' => $lid,
                        'label' => ['show' => true, 'formatter' => 'internal_link_'.$dir],
                    ];
                    $sidePanels[$lid] = $link;
                }
            }
        }

        if ($filters['planning'] && ($payload['planning']['available'] ?? false) === true) {
            $items = is_array($payload['planning']['items']['items'] ?? null)
                ? $payload['planning']['items']['items']
                : [];
            foreach ($items as $task) {
                if (! is_array($task)) {
                    continue;
                }
                $tid = (string) ($task['task_ref'] ?? ('project_task:'.(int) ($task['task_id'] ?? 0)));
                $nodes[] = [
                    'id' => $tid,
                    'name' => (string) ($task['title'] ?: ($task['keyword'] ?: 'Task')),
                    'category' => $ensureCat('Planning'),
                    'symbolSize' => 22,
                    'kind' => 'planning',
                ];
                $edges[] = ['source' => $kwNodeId, 'target' => $tid, 'label' => ['show' => true, 'formatter' => 'planning_task']];
                $sidePanels[$tid] = $task;
            }
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'categories' => $categories,
            'truncations' => [
                'related_keywords' => $payload['related_keywords'] ?? null,
                'internal_links' => [
                    'inbound' => $payload['internal_links']['inbound'] ?? null,
                    'outbound' => $payload['internal_links']['outbound'] ?? null,
                ],
                'gsc' => $payload['gsc']['query_mappings'] ?? null,
                'planning' => $payload['planning']['items'] ?? null,
            ],
            'side_panels' => $sidePanels,
            'filters' => $filters,
            'relation_issues' => $payload['meta']['relation_issues'] ?? [],
            'center' => $kwNodeId,
        ];
    }
}
