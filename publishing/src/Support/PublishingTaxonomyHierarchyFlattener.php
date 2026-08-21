<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Support;

final class PublishingTaxonomyHierarchyFlattener
{
    /**
     * @param  list<array{id?: int, name?: string, parent?: int}>  $items
     * @return list<array{id: int, label: string}>
     */
    public static function flatten(array $items): array
    {
        /** @var array<int, array{id: int, label: string, parent_id: int}> $nodes */
        $nodes = [];

        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            $nodes[$id] = [
                'id' => $id,
                'label' => $name !== '' ? $name : 'Term #'.$id,
                'parent_id' => max(0, (int) ($item['parent'] ?? 0)),
            ];
        }

        if ($nodes === []) {
            return [];
        }

        /** @var array<int, list<int>> $byParent */
        $byParent = [];
        foreach ($nodes as $id => $node) {
            $parentId = isset($nodes[$node['parent_id']]) ? $node['parent_id'] : 0;
            $byParent[$parentId][] = $id;
        }

        foreach ($byParent as &$siblings) {
            usort(
                $siblings,
                static fn (int $leftId, int $rightId): int => strcasecmp(
                    $nodes[$leftId]['label'],
                    $nodes[$rightId]['label'],
                ),
            );
        }
        unset($siblings);

        $result = [];
        $visited = [];

        $walk = function (int $parentId, int $depth) use (&$walk, &$result, &$visited, $byParent, $nodes): void {
            foreach ($byParent[$parentId] ?? [] as $id) {
                if (isset($visited[$id])) {
                    continue;
                }

                $visited[$id] = true;
                $node = $nodes[$id];
                $prefix = $depth > 0 ? str_repeat('— ', $depth) : '';
                $result[] = [
                    'id' => $node['id'],
                    'label' => $prefix.$node['label'],
                ];
                $walk($id, $depth + 1);
            }
        };

        $walk(0, 0);

        foreach (array_keys($nodes) as $id) {
            if (isset($visited[$id])) {
                continue;
            }

            $result[] = [
                'id' => $nodes[$id]['id'],
                'label' => $nodes[$id]['label'],
            ];
        }

        return $result;
    }
}
