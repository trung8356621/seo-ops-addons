<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager;

/**
 * Pure decision helpers mirroring Idea Manager Alpine autosave / conflict rules.
 * Keeps edit-during-inflight and conflict semantics regression-testable in PHP.
 */
final class IdeaWorkspaceClientSyncRules
{
    /**
     * @param  array{status: string, revision?: int, note_items?: list<array<string, mixed>>}  $result
     * @return array{
     *   clear_dirty: bool,
     *   overwrite_working_set: bool,
     *   advance_base_revision: bool,
     *   base_revision: int,
     *   show_conflict: bool,
     *   reschedule_save: bool,
     *   sync_status: string
     * }
     */
    public static function afterSaveResponse(
        array $result,
        int $editGeneration,
        int $editGenerationAtSend,
        int $currentBaseRevision,
    ): array {
        $newer = $editGeneration > $editGenerationAtSend;
        $status = (string) ($result['status'] ?? '');
        $revision = (int) ($result['revision'] ?? $currentBaseRevision);

        if ($status === IdeaWorkspacePersistenceService::STATUS_CONFLICT) {
            return [
                'clear_dirty' => false,
                'overwrite_working_set' => false,
                'advance_base_revision' => false,
                'base_revision' => $currentBaseRevision,
                'show_conflict' => true,
                'reschedule_save' => false,
                'sync_status' => 'unsynced_error',
            ];
        }

        if ($status === IdeaWorkspacePersistenceService::STATUS_OK
            || $status === IdeaWorkspacePersistenceService::STATUS_NOOP) {
            return [
                'clear_dirty' => ! $newer,
                'overwrite_working_set' => false,
                'advance_base_revision' => true,
                'base_revision' => $revision,
                'show_conflict' => false,
                'reschedule_save' => $newer,
                'sync_status' => $newer ? 'editing' : 'synced',
            ];
        }

        return [
            'clear_dirty' => false,
            'overwrite_working_set' => false,
            'advance_base_revision' => false,
            'base_revision' => $currentBaseRevision,
            'show_conflict' => false,
            'reschedule_save' => $newer,
            'sync_status' => 'unsynced_error',
        ];
    }

    /**
     * @return array{use_local: bool, show_conflict: bool, schedule_save: bool}
     */
    public static function reconcileOnOpen(bool $localDirty, int $localBaseRevision, int $serverRevision): array
    {
        if (! $localDirty) {
            return ['use_local' => false, 'show_conflict' => false, 'schedule_save' => false];
        }
        if ($localBaseRevision === $serverRevision) {
            return ['use_local' => true, 'show_conflict' => false, 'schedule_save' => true];
        }

        return ['use_local' => true, 'show_conflict' => true, 'schedule_save' => false];
    }

    /**
     * Global warehouse: conflict if any dirty owner revision diverges from server.
     * Stale autosave must never schedule when intake advanced a workspace revision.
     *
     * @param  array<int|string, int>  $localSiteRevisions
     * @param  array<int|string, int>  $serverSiteRevisions
     * @param  list<int|string>  $dirtyOwnerSiteIds
     * @return array{use_local: bool, show_conflict: bool, schedule_save: bool, conflict_site_id: int}
     */
    public static function reconcileGlobalOnOpen(
        bool $localDirty,
        array $localSiteRevisions,
        array $serverSiteRevisions,
        array $dirtyOwnerSiteIds = [],
    ): array {
        if (! $localDirty) {
            return [
                'use_local' => false,
                'show_conflict' => false,
                'schedule_save' => false,
                'conflict_site_id' => 0,
            ];
        }

        $owners = $dirtyOwnerSiteIds !== []
            ? $dirtyOwnerSiteIds
            : array_keys($localSiteRevisions);

        foreach ($owners as $rawId) {
            $sid = (string) $rawId;
            if (! array_key_exists($sid, $serverSiteRevisions) && ! array_key_exists((int) $sid, $serverSiteRevisions)) {
                continue;
            }
            $localRev = (int) ($localSiteRevisions[$sid] ?? $localSiteRevisions[(int) $sid] ?? 0);
            $serverRev = (int) ($serverSiteRevisions[$sid] ?? $serverSiteRevisions[(int) $sid] ?? 0);
            if ($localRev !== $serverRev) {
                return [
                    'use_local' => true,
                    'show_conflict' => true,
                    'schedule_save' => false,
                    'conflict_site_id' => (int) $sid,
                ];
            }
        }

        return [
            'use_local' => true,
            'show_conflict' => false,
            'schedule_save' => true,
            'conflict_site_id' => 0,
        ];
    }

    /**
     * Merge global warehouse server items with a dirty local draft without
     * silently dropping Ideas from non-dirty owner workspaces.
     *
     * - Dirty owners: keep draft rows (local-first edits).
     * - Clean owners: always take server rows (shows Editor intake on other sites).
     * - Dirty flag without dirty_owners map: keep draft rows, append server-only keys.
     *
     * @param  list<array<string, mixed>>  $serverItems
     * @param  list<array<string, mixed>>  $draftItems
     * @param  array<int|string, mixed>  $dirtyOwners
     * @return list<array<string, mixed>>
     */
    public static function reconcileGlobalWorkingSet(
        array $serverItems,
        array $draftItems,
        array $dirtyOwners = [],
    ): array {
        $dirty = [];
        foreach ($dirtyOwners as $rawId => $flag) {
            if (! $flag) {
                continue;
            }
            $sid = (string) (int) $rawId;
            if ($sid !== '0') {
                $dirty[$sid] = true;
            }
        }

        if ($dirty === []) {
            $seen = [];
            $out = [];
            foreach ($draftItems as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $key = self::warehouseKey($item);
                $seen[$key] = true;
                $out[] = $item;
            }
            foreach ($serverItems as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $key = self::warehouseKey($item);
                if (isset($seen[$key])) {
                    continue;
                }
                $out[] = $item;
            }

            return array_values($out);
        }

        $serverBy = self::partitionByOwner($serverItems);
        $draftBy = self::partitionByOwner($draftItems);
        $owners = array_values(array_unique(array_merge(array_keys($serverBy), array_keys($draftBy))));
        sort($owners, SORT_STRING);

        $out = [];
        foreach ($owners as $sid) {
            $rows = isset($dirty[$sid])
                ? ($draftBy[$sid] ?? [])
                : ($serverBy[$sid] ?? []);
            foreach ($rows as $item) {
                $out[] = $item;
            }
        }

        return array_values($out);
    }

    /**
     * Client-side Kho Idea search — independent of sourceFilterSiteId / targetSiteId.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function filterWarehouseItems(
        array $items,
        string $search = '',
        string $sourceTypeFilter = 'all',
    ): array {
        $q = mb_strtolower(trim($search));
        $src = $sourceTypeFilter !== '' ? $sourceTypeFilter : 'all';
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            if ($src !== 'all') {
                $t = (string) ($item['source_type'] ?? 'cluster');
                if ($src === 'manual' && $t !== 'manual_seed') {
                    continue;
                }
                if ($src === 'cluster' && $t !== 'cluster') {
                    continue;
                }
                if ($src === 'generated' && $t !== 'generated') {
                    continue;
                }
            }
            if ($q === '') {
                $out[] = $item;
                continue;
            }
            $name = mb_strtolower((string) ($item['cluster_name_snapshot'] ?? $item['seed_text'] ?? ''));
            $domain = mb_strtolower((string) ($item['source_domain'] ?? $item['owner_domain'] ?? ''));
            $ref = mb_strtolower((string) ($item['cluster_ref'] ?? ''));
            if (str_contains($name, $q) || str_contains($ref, $q) || str_contains($domain, $q)) {
                $out[] = $item;
            }
        }

        return array_values($out);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function warehouseKey(array $item): string
    {
        if (isset($item['warehouse_key']) && is_string($item['warehouse_key']) && $item['warehouse_key'] !== '') {
            return $item['warehouse_key'];
        }
        $owner = (int) ($item['owner_site_id'] ?? $item['source_site_id'] ?? 0);
        $ref = (string) ($item['cluster_ref'] ?? '');

        return $owner.':'.$ref;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, list<array<string, mixed>>>
     */
    private static function partitionByOwner(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $owner = (int) ($item['owner_site_id'] ?? $item['source_site_id'] ?? 0);
            if ($owner <= 0) {
                continue;
            }
            $out[(string) $owner][] = $item;
        }

        return $out;
    }
}
