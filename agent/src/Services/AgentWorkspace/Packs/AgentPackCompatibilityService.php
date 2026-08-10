<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs;

/**
 * Dependency / conflict / cycle checks for packs.
 */
final class AgentPackCompatibilityService
{
    /**
     * @param  array<string, mixed>  $manifest  normalized
     * @param  array<string, array{status: string, version?: string}>  $knownPacks  key => meta
     * @return array{ok: bool, errors: list<string>, order?: list<string>}
     */
    public function check(array $manifest, array $knownPacks): array
    {
        $errors = [];
        $key = (string) ($manifest['key'] ?? '');
        $deps = is_array($manifest['dependencies'] ?? null) ? $manifest['dependencies'] : [];
        $conflicts = is_array($manifest['conflicts'] ?? null) ? $manifest['conflicts'] : [];

        foreach ($deps as $dep) {
            $dep = (string) $dep;
            if (! isset($knownPacks[$dep])) {
                $errors[] = 'missing_dependency:'.$dep;

                continue;
            }
            $status = (string) ($knownPacks[$dep]['status'] ?? '');
            if (! in_array($status, ['enabled', 'installed', 'discovered'], true)) {
                $errors[] = 'dependency_unavailable:'.$dep;
            }
        }

        foreach ($conflicts as $conflict) {
            $conflict = (string) $conflict;
            if (isset($knownPacks[$conflict]) && ($knownPacks[$conflict]['status'] ?? '') === 'enabled') {
                $errors[] = 'active_conflict:'.$conflict;
            }
        }

        $graph = [];
        foreach ($knownPacks as $k => $meta) {
            $graph[$k] = is_array($meta['dependencies'] ?? null) ? array_map('strval', $meta['dependencies']) : [];
        }
        $graph[$key] = array_map('strval', $deps);

        if ($this->hasCycle($graph, $key)) {
            $errors[] = 'circular_dependency';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return [
            'ok' => true,
            'errors' => [],
            'order' => $this->topoSort($graph, $key),
        ];
    }

    /**
     * @param  array<string, list<string>>  $graph
     */
    private function hasCycle(array $graph, string $start): bool
    {
        $visiting = [];
        $visited = [];

        $dfs = function (string $node) use (&$dfs, &$visiting, &$visited, $graph): bool {
            if (isset($visiting[$node])) {
                return true;
            }
            if (isset($visited[$node])) {
                return false;
            }
            $visiting[$node] = true;
            foreach ($graph[$node] ?? [] as $dep) {
                if ($dfs((string) $dep)) {
                    return true;
                }
            }
            unset($visiting[$node]);
            $visited[$node] = true;

            return false;
        };

        return $dfs($start);
    }

    /**
     * Deterministic dependency-first order for $focus and its deps.
     *
     * @param  array<string, list<string>>  $graph
     * @return list<string>
     */
    private function topoSort(array $graph, string $focus): array
    {
        $needed = [];
        $stack = [$focus];
        while ($stack !== []) {
            $n = array_pop($stack);
            if (isset($needed[$n])) {
                continue;
            }
            $needed[$n] = true;
            foreach ($graph[$n] ?? [] as $dep) {
                $stack[] = (string) $dep;
            }
        }

        $order = [];
        $temp = [];
        $perm = [];
        $visit = function (string $n) use (&$visit, &$temp, &$perm, &$order, $graph, $needed): void {
            if (! isset($needed[$n]) || isset($perm[$n])) {
                return;
            }
            if (isset($temp[$n])) {
                return;
            }
            $temp[$n] = true;
            $deps = $graph[$n] ?? [];
            sort($deps);
            foreach ($deps as $dep) {
                $visit((string) $dep);
            }
            unset($temp[$n]);
            $perm[$n] = true;
            $order[] = $n;
        };

        $keys = array_keys($needed);
        sort($keys);
        foreach ($keys as $k) {
            $visit($k);
        }

        return $order;
    }
}
