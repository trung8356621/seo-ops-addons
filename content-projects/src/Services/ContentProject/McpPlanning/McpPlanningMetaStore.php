<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\McpPlanning;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Illuminate\Support\Facades\Schema;

/**
 * Read/write project.meta.mcp_planning without clearing other meta keys.
 */
final class McpPlanningMetaStore
{
    public function metaColumnAvailable(): bool
    {
        return Schema::connection('omi_seo_ai')->hasColumn('seo_projects', 'meta');
    }

    /**
     * @return list<array{
     *     project_item_id: int,
     *     source_planning_item_id: int|null,
     *     site_id: int,
     *     cluster_key: string|null,
     *     keyword_id: int|null,
     *     approved_at: string|null
     * }>
     */
    public function items(SeoProject $project): array
    {
        if (! $this->metaColumnAvailable()) {
            return [];
        }

        $meta = is_array($project->meta) ? $project->meta : [];

        return McpPlanningMeta::itemsFromBag($meta[McpPlanningMeta::META_KEY] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function putItems(SeoProject $project, array $entries): void
    {
        if (! $this->metaColumnAvailable()) {
            return;
        }

        $meta = is_array($project->meta) ? $project->meta : [];
        $wrapped = McpPlanningMeta::wrap($entries);
        if ($wrapped[McpPlanningMeta::ITEMS_KEY] === []) {
            unset($meta[McpPlanningMeta::META_KEY]);
        } else {
            $meta[McpPlanningMeta::META_KEY] = $wrapped;
        }

        $project->forceFill(['meta' => $meta === [] ? null : $meta])->saveQuietly();
    }

    /**
     * Append / upsert entries by project_item_id (no duplicates).
     *
     * @param  list<array<string, mixed>>  $entries
     */
    public function upsertItems(SeoProject $project, array $entries): void
    {
        $byId = [];
        foreach ($this->items($project) as $existing) {
            $byId[(int) $existing['project_item_id']] = $existing;
        }
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $normalized = McpPlanningMeta::normalizeEntry($entry);
            if ($normalized === null) {
                continue;
            }
            $byId[$normalized['project_item_id']] = $normalized;
        }

        $this->putItems($project, array_values($byId));
    }

    /**
     * @param  list<int>  $projectItemIds
     */
    public function removeItems(SeoProject $project, array $projectItemIds): void
    {
        $ids = array_fill_keys(array_filter(array_map('intval', $projectItemIds)), true);
        if ($ids === []) {
            return;
        }

        $kept = [];
        foreach ($this->items($project) as $item) {
            if (! isset($ids[(int) $item['project_item_id']])) {
                $kept[] = $item;
            }
        }

        $this->putItems($project, $kept);
    }

    /**
     * Clear only mcp_planning — preserve other meta keys.
     */
    public function clear(SeoProject $project): void
    {
        if (! $this->metaColumnAvailable()) {
            return;
        }

        $meta = is_array($project->meta) ? $project->meta : [];
        if (! array_key_exists(McpPlanningMeta::META_KEY, $meta)) {
            return;
        }

        unset($meta[McpPlanningMeta::META_KEY]);
        $project->forceFill(['meta' => $meta === [] ? null : $meta])->saveQuietly();
    }

    /**
     * Move planning entries for given item ids from source → destination.
     *
     * @param  list<int>  $projectItemIds
     * @return list<array<string, mixed>>  moved entries
     */
    public function moveItems(SeoProject $source, SeoProject $destination, array $projectItemIds): array
    {
        $ids = array_fill_keys(array_filter(array_map('intval', $projectItemIds)), true);
        if ($ids === []) {
            return [];
        }

        $moving = [];
        $kept = [];
        foreach ($this->items($source) as $item) {
            if (isset($ids[(int) $item['project_item_id']])) {
                $moving[] = $item;
            } else {
                $kept[] = $item;
            }
        }

        if ($moving === []) {
            return [];
        }

        $this->putItems($source, $kept);
        $this->upsertItems($destination, $moving);

        return $moving;
    }
}
