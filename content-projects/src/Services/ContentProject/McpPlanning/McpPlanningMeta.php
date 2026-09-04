<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\McpPlanning;

/**
 * Project.meta.mcp_planning — per-item MCP pipeline signal after Draft → Execution.
 *
 * Does not store content/prompt/article body.
 */
final class McpPlanningMeta
{
    public const META_KEY = 'mcp_planning';

    public const ITEMS_KEY = 'items';

    /**
     * @param  array{
     *     project_item_id: int,
     *     source_planning_item_id?: int|null,
     *     site_id: int,
     *     cluster_key?: string|null,
     *     keyword_id?: int|null,
     *     approved_at?: string|null
     * }  $entry
     * @return array{
     *     project_item_id: int,
     *     source_planning_item_id: int|null,
     *     site_id: int,
     *     cluster_key: string|null,
     *     keyword_id: int|null,
     *     approved_at: string|null
     * }
     */
    public static function normalizeEntry(array $entry): ?array
    {
        $itemId = (int) ($entry['project_item_id'] ?? 0);
        $siteId = (int) ($entry['site_id'] ?? 0);
        if ($itemId <= 0 || $siteId <= 0) {
            return null;
        }

        $clusterKey = trim((string) ($entry['cluster_key'] ?? ''));
        $keywordId = (int) ($entry['keyword_id'] ?? 0);
        $sourceId = (int) ($entry['source_planning_item_id'] ?? $itemId);
        $approvedAt = trim((string) ($entry['approved_at'] ?? ''));

        return [
            'project_item_id' => $itemId,
            'source_planning_item_id' => $sourceId > 0 ? $sourceId : $itemId,
            'site_id' => $siteId,
            'cluster_key' => $clusterKey !== '' ? $clusterKey : null,
            'keyword_id' => $keywordId > 0 ? $keywordId : null,
            'approved_at' => $approvedAt !== '' ? $approvedAt : null,
        ];
    }

    /**
     * @param  mixed  $raw
     * @return list<array{
     *     project_item_id: int,
     *     source_planning_item_id: int|null,
     *     site_id: int,
     *     cluster_key: string|null,
     *     keyword_id: int|null,
     *     approved_at: string|null
     * }>
     */
    public static function itemsFromBag(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $bucket = is_array($raw[self::ITEMS_KEY] ?? null) ? $raw[self::ITEMS_KEY] : $raw;
        if (! is_array($bucket)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($bucket as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = self::normalizeEntry($row);
            if ($normalized === null) {
                continue;
            }
            $id = $normalized['project_item_id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $normalized;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{items: list<array<string, mixed>>}
     */
    public static function wrap(array $items): array
    {
        return [self::ITEMS_KEY => self::itemsFromBag([self::ITEMS_KEY => $items])];
    }
}
