<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Directed workflow graph helpers — reachable descendants from a start node.
 */
final class WorkflowGraphReachability
{
    /**
     * @param  list<array<string, mixed>>  $edges
     * @return list<string>
     */
    public static function reachableNodeIdsFrom(string $startNodeId, array $edges): array
    {
        $startNodeId = trim($startNodeId);
        if ($startNodeId === '') {
            return [];
        }

        $adjacency = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $source = trim((string) ($edge['sourceNode'] ?? ''));
            $target = trim((string) ($edge['targetNode'] ?? ''));
            if ($source === '' || $target === '') {
                continue;
            }
            $adjacency[$source][] = $target;
        }

        $reachable = [];
        $queue = [$startNodeId];
        while ($queue !== []) {
            $id = array_shift($queue);
            if ($id === null || isset($reachable[$id])) {
                continue;
            }
            $reachable[$id] = true;
            foreach ($adjacency[$id] ?? [] as $nextId) {
                if (! isset($reachable[$nextId])) {
                    $queue[] = $nextId;
                }
            }
        }

        return array_keys($reachable);
    }

    /**
     * Intersection: topological order ∩ reachable descendants.
     *
     * @param  list<array<string, mixed>>  $orderedNodes  Topologically sorted full graph
     * @param  list<string>  $reachableIds
     * @return list<array<string, mixed>>
     */
    public static function filterOrderedNodes(array $orderedNodes, array $reachableIds): array
    {
        if ($reachableIds === []) {
            return [];
        }
        $lookup = array_fill_keys($reachableIds, true);
        $out = [];
        foreach ($orderedNodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = trim((string) ($node['id'] ?? ''));
            if ($id !== '' && isset($lookup[$id])) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * True when any direct predecessor (within reachable subgraph) failed/was blocked/skipped upstream.
     *
     * @param  list<array<string, mixed>>  $edges
     * @param  array<string, string>  $statusByNodeId
     * @param  list<string>  $reachableIds
     */
    public static function hasBlockedPredecessor(
        string $nodeId,
        array $edges,
        array $statusByNodeId,
        array $reachableIds,
    ): bool {
        $reachableLookup = array_fill_keys($reachableIds, true);
        $blocking = ['failed', 'blocked', 'skipped_upstream'];

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $source = trim((string) ($edge['sourceNode'] ?? ''));
            $target = trim((string) ($edge['targetNode'] ?? ''));
            if ($target !== $nodeId || $source === '') {
                continue;
            }
            if (! isset($reachableLookup[$source], $reachableLookup[$target])) {
                continue;
            }
            $status = $statusByNodeId[$source] ?? '';
            if (in_array($status, $blocking, true)) {
                return true;
            }
        }

        return false;
    }
}
