<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectIdeaGroup;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectIdeaWorkspace;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Manages up to 5 Kho Idea groups per owner/workspace:
 * 1 permanent system group ("Chung") + up to 4 manual user groups.
 */
final class IdeaGroupService
{
    public function resolveOwnerUserId(?int $actorId = null): int
    {
        $owner = SeoAccessControl::accountSiteOwnerId();
        if ($owner !== null && $owner > 0) {
            return (int) $owner;
        }
        if ($actorId !== null && $actorId > 0) {
            return $actorId;
        }
        $authId = auth()->id();

        return $authId !== null && (int) $authId > 0 ? (int) $authId : 0;
    }

    public function resolveOwnerUserIdForSite(int $siteId, ?int $actorId = null): int
    {
        if ($siteId > 0) {
            $siteOwner = (int) (Site::query()->whereKey($siteId)->value('user_id') ?? 0);
            if ($siteOwner > 0) {
                return $siteOwner;
            }
        }

        return $this->resolveOwnerUserId($actorId);
    }

    public function ensureSystemGroup(?int $actorId = null): SeoContentProjectIdeaGroup
    {
        $ownerId = $this->resolveOwnerUserId($actorId);

        return $this->ensureSystemGroupForOwner($ownerId);
    }

    public function ensureSystemGroupIdForSite(int $siteId, ?int $actorId = null): int
    {
        $ownerId = $this->resolveOwnerUserIdForSite($siteId, $actorId);
        if ($ownerId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_idea_groups')) {
            return 0;
        }

        return (int) $this->ensureSystemGroupForOwner($ownerId)->getKey();
    }

    public function ensureSystemGroupForOwner(int $ownerUserId): SeoContentProjectIdeaGroup
    {
        abort_unless($ownerUserId > 0, 403);

        $existing = SeoContentProjectIdeaGroup::query()
            ->where('owner_user_id', $ownerUserId)
            ->where('is_system', true)
            ->orderBy('id')
            ->first();
        if ($existing instanceof SeoContentProjectIdeaGroup) {
            return $existing;
        }

        $lock = Cache::lock('idea_group_system:'.$ownerUserId, 10);
        try {
            $lock->block(5);
            $again = SeoContentProjectIdeaGroup::query()
                ->where('owner_user_id', $ownerUserId)
                ->where('is_system', true)
                ->orderBy('id')
                ->first();
            if ($again instanceof SeoContentProjectIdeaGroup) {
                return $again;
            }

            /** @var SeoContentProjectIdeaGroup $created */
            $created = SeoContentProjectIdeaGroup::query()->create([
                'owner_user_id' => $ownerUserId,
                'name' => SeoContentProjectIdeaGroup::SYSTEM_GROUP_NAME,
                'is_system' => true,
                'sort_order' => 0,
            ]);

            return $created;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return list<array{id: int, name: string, is_system: bool, sort_order: int}>
     */
    public function listGroups(?int $actorId = null): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        $ownerId = $this->resolveOwnerUserId($actorId);
        if ($ownerId <= 0) {
            return [];
        }
        $this->ensureSystemGroupForOwner($ownerId);

        return SeoContentProjectIdeaGroup::query()
            ->where('owner_user_id', $ownerId)
            ->orderByDesc('is_system')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (SeoContentProjectIdeaGroup $g): array => [
                'id' => (int) $g->getKey(),
                'name' => (string) $g->name,
                'is_system' => (bool) $g->is_system,
                'sort_order' => (int) $g->sort_order,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function createGroup(string $name, ?int $actorId = null): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        $ownerId = $this->resolveOwnerUserId($actorId);
        abort_unless($ownerId > 0, 403);

        $trimmed = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($trimmed === '') {
            return ['ok' => false, 'code' => 'empty_name', 'message' => 'Tên nhóm không được để trống.'];
        }
        if (mb_strlen($trimmed, 'UTF-8') > SeoContentProjectIdeaGroup::MAX_NAME_LENGTH) {
            return ['ok' => false, 'code' => 'name_too_long', 'message' => 'Tên nhóm không được vượt quá 120 ký tự.'];
        }

        $this->ensureSystemGroupForOwner($ownerId);

        return DB::connection('omi_seo_ai')->transaction(function () use ($ownerId, $trimmed): array {
            $groups = SeoContentProjectIdeaGroup::query()
                ->where('owner_user_id', $ownerId)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            if ($groups->count() >= SeoContentProjectIdeaGroup::MAX_GROUPS_PER_OWNER) {
                return [
                    'ok' => false,
                    'code' => 'max_groups_reached',
                    'message' => 'Tối đa 5 nhóm trong Kho Idea (1 nhóm Chung + 4 nhóm thủ công).',
                ];
            }

            $needle = mb_strtolower($trimmed, 'UTF-8');
            foreach ($groups as $g) {
                if (mb_strtolower(trim((string) $g->name), 'UTF-8') === $needle) {
                    return [
                        'ok' => false,
                        'code' => 'duplicate_name',
                        'message' => 'Tên nhóm này đã tồn tại.',
                    ];
                }
            }

            $maxSort = (int) ($groups->max('sort_order') ?? 0);
            /** @var SeoContentProjectIdeaGroup $created */
            $created = SeoContentProjectIdeaGroup::query()->create([
                'owner_user_id' => $ownerId,
                'name' => $trimmed,
                'is_system' => false,
                'sort_order' => max(1, $maxSort + 1),
            ]);

            return [
                'ok' => true,
                'group' => [
                    'id' => (int) $created->getKey(),
                    'name' => (string) $created->name,
                    'is_system' => false,
                    'sort_order' => (int) $created->sort_order,
                ],
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function renameGroup(int $groupId, string $name, ?int $actorId = null): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        $ownerId = $this->resolveOwnerUserId($actorId);
        abort_unless($ownerId > 0, 403);

        $trimmed = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($trimmed === '') {
            return ['ok' => false, 'code' => 'empty_name', 'message' => 'Tên nhóm không được để trống.'];
        }
        if (mb_strlen($trimmed, 'UTF-8') > SeoContentProjectIdeaGroup::MAX_NAME_LENGTH) {
            return ['ok' => false, 'code' => 'name_too_long', 'message' => 'Tên nhóm không được vượt quá 120 ký tự.'];
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($ownerId, $groupId, $trimmed): array {
            $groups = SeoContentProjectIdeaGroup::query()
                ->where('owner_user_id', $ownerId)
                ->lockForUpdate()
                ->get();

            /** @var SeoContentProjectIdeaGroup|null $target */
            $target = $groups->firstWhere('id', $groupId);
            if (! $target instanceof SeoContentProjectIdeaGroup) {
                return ['ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy nhóm hoặc không có quyền.'];
            }
            if ((bool) $target->is_system) {
                return ['ok' => false, 'code' => 'system_group_protected', 'message' => 'Không thể đổi tên nhóm mặc định Chung.'];
            }

            $needle = mb_strtolower($trimmed, 'UTF-8');
            foreach ($groups as $g) {
                if ((int) $g->getKey() !== $groupId
                    && mb_strtolower(trim((string) $g->name), 'UTF-8') === $needle
                ) {
                    return ['ok' => false, 'code' => 'duplicate_name', 'message' => 'Tên nhóm này đã tồn tại.'];
                }
            }

            $target->name = $trimmed;
            $target->save();

            return [
                'ok' => true,
                'group' => [
                    'id' => (int) $target->getKey(),
                    'name' => (string) $target->name,
                    'is_system' => false,
                    'sort_order' => (int) $target->sort_order,
                ],
            ];
        });
    }

    /**
     * Move Ideas between groups by updating ONLY idea_group_id (no copy, no metadata loss).
     *
     * @param  list<array{site_id: int, cluster_ref: string}>  $targets
     * @return array<string, mixed>
     */
    public function moveIdeas(array $targets, int $targetGroupId, ?int $actorId = null): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        $ownerId = $this->resolveOwnerUserId($actorId);
        abort_unless($ownerId > 0, 403);

        $group = SeoContentProjectIdeaGroup::query()
            ->where('owner_user_id', $ownerId)
            ->whereKey($targetGroupId)
            ->first();
        if (! $group instanceof SeoContentProjectIdeaGroup) {
            return ['ok' => false, 'code' => 'group_not_found', 'message' => 'Nhóm đích không tồn tại hoặc không có quyền.'];
        }

        $refsBySite = [];
        foreach ($targets as $t) {
            if (! is_array($t)) {
                continue;
            }
            $sid = (int) ($t['site_id'] ?? 0);
            $ref = trim((string) ($t['cluster_ref'] ?? ''));
            if ($sid <= 0 || $ref === '') {
                continue;
            }
            abort_unless(SeoAccessControl::canAccessSite($sid), 403);
            $refsBySite[$sid][$ref] = true;
        }

        if ($refsBySite === []) {
            return ['ok' => false, 'code' => 'empty_selection', 'message' => 'Chưa chọn Idea nào để chuyển nhóm.'];
        }

        $movedCount = 0;
        DB::connection('omi_seo_ai')->transaction(function () use ($refsBySite, $targetGroupId, $actorId, &$movedCount): void {
            foreach ($refsBySite as $siteId => $refMap) {
                /** @var SeoContentProjectIdeaWorkspace|null $ws */
                $ws = SeoContentProjectIdeaWorkspace::query()
                    ->where('site_id', $siteId)
                    ->lockForUpdate()
                    ->first();
                if ($ws === null) {
                    continue;
                }

                $items = AuditNoteDnaNormalizer::normalizeNoteItems(
                    is_array($ws->note_items) ? $ws->note_items : [],
                );
                $changed = false;
                foreach ($items as $idx => $item) {
                    $ref = trim((string) ($item['cluster_ref'] ?? ''));
                    if ($ref !== '' && isset($refMap[$ref])) {
                        if ((int) ($item['idea_group_id'] ?? 0) !== $targetGroupId) {
                            $items[$idx]['idea_group_id'] = $targetGroupId;
                            $changed = true;
                            $movedCount++;
                        }
                    }
                }

                if ($changed) {
                    $normalized = AuditNoteDnaNormalizer::normalizeNoteItems($items);
                    $ws->note_items = $normalized;
                    $ws->content_hash = IdeaWorkspacePersistenceService::contentHash($normalized);
                    $ws->revision = (int) $ws->revision + 1;
                    $ws->updated_by = $actorId;
                    $ws->save();
                }
            }
        });

        return [
            'ok' => true,
            'moved_count' => $movedCount,
            'target_group_id' => $targetGroupId,
            'target_group_name' => (string) $group->name,
        ];
    }

    /**
     * Bulk-set target_dna_count for every Idea in the active group across all accessible workspaces.
     *
     * @return array<string, mixed>
     */
    public function bulkUpdateTargetDna(int $groupId, int $targetDnaCount, ?int $actorId = null): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        $ownerId = $this->resolveOwnerUserId($actorId);
        abort_unless($ownerId > 0, 403);

        if ($targetDnaCount < AuditNoteDnaNormalizer::MIN_TARGET_DNA_COUNT
            || $targetDnaCount > AuditNoteDnaNormalizer::MAX_TARGET_DNA_COUNT
        ) {
            return [
                'ok' => false,
                'code' => 'invalid_target_dna',
                'message' => sprintf(
                    'DNA mục tiêu phải từ %d đến %d.',
                    AuditNoteDnaNormalizer::MIN_TARGET_DNA_COUNT,
                    AuditNoteDnaNormalizer::MAX_TARGET_DNA_COUNT,
                ),
            ];
        }

        $group = SeoContentProjectIdeaGroup::query()
            ->where('owner_user_id', $ownerId)
            ->whereKey($groupId)
            ->first();
        if (! $group instanceof SeoContentProjectIdeaGroup) {
            return ['ok' => false, 'code' => 'group_not_found', 'message' => 'Không tìm thấy nhóm hoặc không có quyền.'];
        }

        $systemGroup = $this->ensureSystemGroupForOwner($ownerId);
        $systemGroupId = (int) $systemGroup->getKey();
        $siteIds = SeoAccessControl::accessibleSitesQuery()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($siteIds === []) {
            return ['ok' => false, 'code' => 'empty_group', 'message' => 'Nhóm hiện không có Idea nào để cập nhật.'];
        }

        $updatedCount = 0;
        DB::connection('omi_seo_ai')->transaction(function () use (
            $siteIds,
            $groupId,
            $systemGroupId,
            $targetDnaCount,
            $actorId,
            &$updatedCount,
        ): void {
            $workspaces = SeoContentProjectIdeaWorkspace::query()
                ->whereIn('site_id', $siteIds)
                ->lockForUpdate()
                ->get();

            foreach ($workspaces as $ws) {
                $items = AuditNoteDnaNormalizer::normalizeNoteItems(
                    is_array($ws->note_items) ? $ws->note_items : [],
                );
                $changed = false;
                foreach ($items as $idx => $item) {
                    $itemGroupId = (int) ($item['idea_group_id'] ?? 0);
                    if ($itemGroupId <= 0) {
                        $itemGroupId = $systemGroupId;
                    }
                    if ($itemGroupId !== $groupId) {
                        continue;
                    }

                    $dna = is_array($item['dna'] ?? null) ? $item['dna'] : [];
                    $nextTarget = AuditNoteDnaNormalizer::ensureTargetCoversSpecified($targetDnaCount, $dna);
                    $updatedCount++;
                    if ((int) ($item['target_dna_count'] ?? 0) !== $nextTarget) {
                        $items[$idx]['target_dna_count'] = $nextTarget;
                        $changed = true;
                    }
                }

                if ($changed) {
                    $normalized = AuditNoteDnaNormalizer::normalizeNoteItems($items);
                    $ws->note_items = $normalized;
                    $ws->content_hash = IdeaWorkspacePersistenceService::contentHash($normalized);
                    $ws->revision = (int) $ws->revision + 1;
                    $ws->updated_by = $actorId;
                    $ws->save();
                }
            }
        });

        if ($updatedCount === 0) {
            return ['ok' => false, 'code' => 'empty_group', 'message' => 'Nhóm hiện không có Idea nào để cập nhật.'];
        }

        return [
            'ok' => true,
            'group_id' => $groupId,
            'group_name' => (string) $group->name,
            'target_dna_count' => $targetDnaCount,
            'updated_count' => $updatedCount,
            'message' => sprintf('Đã áp dụng DNA mục tiêu = %d cho %d Idea trong nhóm %s.', $targetDnaCount, $updatedCount, (string) $group->name),
        ];
    }

    /**
     * Delete a manual group AND all Kho Idea items inside it (never moves to Chung,
     * never touches Articles / Drafts / Tasks / Planner Runs).
     *
     * @return array<string, mixed>
     */
    public function deleteGroupAndIdeas(int $groupId, ?int $actorId = null): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        $ownerId = $this->resolveOwnerUserId($actorId);
        abort_unless($ownerId > 0, 403);

        $systemGroup = $this->ensureSystemGroupForOwner($ownerId);
        $target = SeoContentProjectIdeaGroup::query()
            ->where('owner_user_id', $ownerId)
            ->whereKey($groupId)
            ->first();

        if (! $target instanceof SeoContentProjectIdeaGroup) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy nhóm hoặc không có quyền.'];
        }
        if ((bool) $target->is_system) {
            return ['ok' => false, 'code' => 'system_group_protected', 'message' => 'Không thể xóa nhóm mặc định Chung.'];
        }

        $siteIds = SeoAccessControl::accessibleSitesQuery()->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        // Safety: block deletion if an active Generate Run is currently using any Idea in this group.
        if ($this->hasActiveGenerateRunUsingGroup($groupId, $siteIds)) {
            return [
                'ok' => false,
                'code' => 'active_generate_run',
                'message' => 'Nhóm đang có Idea thuộc lượt Generate AI đang chạy. Vui lòng đợi hoàn tất trước khi xóa.',
            ];
        }

        $deletedIdeas = 0;
        $groupName = (string) $target->name;

        DB::connection('omi_seo_ai')->transaction(function () use ($ownerId, $groupId, $siteIds, $actorId, &$deletedIdeas): void {
            /** @var SeoContentProjectIdeaGroup|null $lockedGroup */
            $lockedGroup = SeoContentProjectIdeaGroup::query()
                ->where('owner_user_id', $ownerId)
                ->whereKey($groupId)
                ->lockForUpdate()
                ->first();

            if (! $lockedGroup instanceof SeoContentProjectIdeaGroup || (bool) $lockedGroup->is_system) {
                return;
            }

            if ($siteIds !== []) {
                $workspaces = SeoContentProjectIdeaWorkspace::query()
                    ->whereIn('site_id', $siteIds)
                    ->lockForUpdate()
                    ->get();

                foreach ($workspaces as $ws) {
                    $items = AuditNoteDnaNormalizer::normalizeNoteItems(
                        is_array($ws->note_items) ? $ws->note_items : [],
                    );
                    $kept = [];
                    $removedHere = 0;
                    foreach ($items as $item) {
                        if ((int) ($item['idea_group_id'] ?? 0) === $groupId) {
                            $removedHere++;
                            continue;
                        }
                        $kept[] = $item;
                    }

                    if ($removedHere > 0) {
                        $deletedIdeas += $removedHere;
                        $normalized = AuditNoteDnaNormalizer::normalizeNoteItems($kept);
                        $ws->note_items = $normalized;
                        $ws->content_hash = IdeaWorkspacePersistenceService::contentHash($normalized);
                        $ws->revision = (int) $ws->revision + 1;
                        $ws->updated_by = $actorId;
                        $ws->save();
                    }
                }
            }

            $lockedGroup->delete();
        });

        return [
            'ok' => true,
            'deleted_group_id' => $groupId,
            'deleted_group_name' => $groupName,
            'deleted_ideas_count' => $deletedIdeas,
            'default_group_id' => (int) $systemGroup->getKey(),
        ];
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function hasActiveGenerateRunUsingGroup(int $groupId, array $siteIds): bool
    {
        if ($siteIds === []) {
            return false;
        }

        $groupRefs = [];
        $workspaces = SeoContentProjectIdeaWorkspace::query()
            ->whereIn('site_id', $siteIds)
            ->get(['note_items']);
        foreach ($workspaces as $ws) {
            foreach ((is_array($ws->note_items) ? $ws->note_items : []) as $item) {
                if (is_array($item) && (int) ($item['idea_group_id'] ?? 0) === $groupId) {
                    $ref = trim((string) ($item['cluster_ref'] ?? ''));
                    if ($ref !== '') {
                        $groupRefs[$ref] = true;
                    }
                }
            }
        }
        if ($groupRefs === []) {
            return false;
        }

        $draft = app(PlanningDraftResolver::class)->findCanonicalSharedDraft();
        if ($draft === null) {
            return false;
        }

        $runs = SeoContentProjectPlannerRun::query()
            ->where('project_id', (int) $draft->getKey())
            ->where('source_type', SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT)
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        foreach ($runs as $run) {
            $summary = is_array($run->result_summary) ? $run->result_summary : [];
            $status = (string) ($summary['status'] ?? '');
            if (! in_array($status, SeoContentProjectPlannerRun::activeStatuses(), true)) {
                continue;
            }
            $updatedAt = $run->updated_at ?? $run->created_at;
            $age = $updatedAt !== null ? max(0, (int) $updatedAt->diffInSeconds(now(), false)) : 0;
            if ($age > 1200) {
                continue;
            }
            $snap = is_array($run->configuration_snapshot) ? $run->configuration_snapshot : [];
            $noteItems = is_array($snap['note_items'] ?? null) ? $snap['note_items'] : [];
            foreach ($noteItems as $ni) {
                $ref = is_array($ni) ? trim((string) ($ni['cluster_ref'] ?? '')) : '';
                if ($ref !== '' && isset($groupRefs[$ref])) {
                    return true;
                }
            }
        }

        return false;
    }
}
