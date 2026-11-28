<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectIdeaManager;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectIdeaGroup;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaGroupService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaManagerKeywordIntakeService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspaceWarehouseReadService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class IdeaManagerGroupsTest extends TestCase
{
    public function test_idea_group_model_and_migration_schema_and_backfill_contract(): void
    {
        self::assertSame('Chung', SeoContentProjectIdeaGroup::SYSTEM_GROUP_NAME);
        self::assertSame(5, SeoContentProjectIdeaGroup::MAX_GROUPS_PER_OWNER);
        self::assertSame(4, SeoContentProjectIdeaGroup::MAX_MANUAL_GROUPS);

        $migrationPath = dirname(__DIR__, 2).'/database/migrations/2026_11_28_100000_create_seo_content_project_idea_groups_table.php';
        self::assertFileExists($migrationPath);
        $migSrc = (string) file_get_contents($migrationPath);
        self::assertStringContainsString('seo_content_project_idea_groups', $migSrc);
        self::assertStringContainsString('owner_user_id', $migSrc);
        self::assertStringContainsString('is_system', $migSrc);
        self::assertStringContainsString('sort_order', $migSrc);
        self::assertStringContainsString('backfillExistingWorkspaces', $migSrc);
    }

    public function test_normalizer_preserves_idea_group_id_and_system_intake_targets_chung(): void
    {
        $normalized = AuditNoteDnaNormalizer::normalizeNoteItem([
            'source_type' => AuditNoteDnaNormalizer::SOURCE_TYPE_MANUAL_SEED,
            'cluster_ref' => 'manual:abc123',
            'cluster_name_snapshot' => 'Balo laptop',
            'seed_text' => 'Balo laptop',
            'idea_group_id' => 42,
        ]);

        self::assertIsArray($normalized);
        self::assertSame(42, $normalized['idea_group_id'] ?? null);

        // System intake (Keyword / SEO Audit MoveSharedDraftToIdeaManagerService) assigns Chung.
        $intakeSrc = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaManagerKeywordIntakeService::class))->getFileName(),
        );
        self::assertStringContainsString('ensureSystemGroupIdForSite', $intakeSrc);
        self::assertStringContainsString("\$item['idea_group_id'] = \$chungGroupId;", $intakeSrc);

        // Warehouse read falls back missing/invalid idea_group_id to Chung and exposes groups.
        $readSrc = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaWorkspaceWarehouseReadService::class))->getFileName(),
        );
        self::assertStringContainsString('listGroups(', $readSrc);
        self::assertStringContainsString("'groups' => \$groups", $readSrc);
        self::assertStringContainsString("'default_group_id' => \$defaultGroupId", $readSrc);
    }

    public function test_idea_group_service_enforces_limits_ownership_move_and_safe_delete(): void
    {
        $svcSrc = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaGroupService::class))->getFileName(),
        );

        // System group always ensured; max 5 groups; system group protected from rename/delete.
        self::assertStringContainsString('ensureSystemGroupForOwner', $svcSrc);
        self::assertStringContainsString('MAX_GROUPS_PER_OWNER', $svcSrc);
        self::assertStringContainsString("'code' => 'max_groups_reached'", $svcSrc);
        self::assertStringContainsString("'code' => 'system_group_protected'", $svcSrc);
        self::assertStringContainsString('->where(\'owner_user_id\', $ownerId)', $svcSrc);
        self::assertStringContainsString('SeoAccessControl::canAccessSite($sid)', $svcSrc);

        // Move updates idea_group_id in-place without cloning.
        self::assertStringContainsString('$items[$idx][\'idea_group_id\'] = $targetGroupId;', $svcSrc);

        // Delete removes group + its Ideas in transaction, blocks on active Generate Run,
        // and never touches Articles / Drafts / Tasks / Planner Runs.
        self::assertStringContainsString('hasActiveGenerateRunUsingGroup', $svcSrc);
        self::assertStringContainsString("'code' => 'active_generate_run'", $svcSrc);
        self::assertStringContainsString('if ((int) ($item[\'idea_group_id\'] ?? 0) === $groupId)', $svcSrc);
        self::assertStringNotContainsString('SeoProjectTask', $svcSrc);
        self::assertStringNotContainsString('SeoArticle', $svcSrc);
        self::assertStringNotContainsString('delete()', substr($svcSrc, 0, strpos($svcSrc, '$lockedGroup->delete()') ?: 0));

        // Livewire page exposes all 4 group endpoints with workflow auth.
        $pageSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectIdeaManager::class))->getFileName(),
        );
        self::assertStringContainsString('function createIdeaGroup(string $name): array', $pageSrc);
        self::assertStringContainsString('function renameIdeaGroup(int $groupId, string $name): array', $pageSrc);
        self::assertStringContainsString('function deleteIdeaGroup(int $groupId): array', $pageSrc);
        self::assertStringContainsString('function moveIdeasToGroup(array $targets, int $targetGroupId): array', $pageSrc);
    }

    public function test_blade_renders_tabs_switches_clear_selection_and_deletes_with_confirmation(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');

        self::assertStringContainsString('data-im-group-bar="1"', $blade);
        self::assertStringContainsString('groups.length < 5', $blade);
        self::assertStringContainsString('data-im-group-add="1"', $blade);
        self::assertStringContainsString('data-im-group-rename="1"', $blade);
        self::assertStringContainsString('data-im-group-delete="1"', $blade);
        self::assertStringContainsString('data-im-group-delete-modal="1"', $blade);
        self::assertStringContainsString('data-im-move-group-bar="1"', $blade);

        // Switching group clears selection; filteredItems scopes by activeGroupId.
        self::assertStringContainsString('selectGroup(groupId)', $blade);
        self::assertStringContainsString('this.clearSelection();', $blade);
        self::assertStringContainsString('if (itemGid !== activeGid) return false;', $blade);

        // Manual adds stamp activeGroupId; inline rename remains present.
        self::assertStringContainsString('idea_group_id: Number(this.activeGroupId || this.defaultGroupId || 0)', $blade);
        self::assertStringContainsString('x-on:dblclick.stop="startRename(item)"', $blade);

        // Group tabs sit below toolbar & DNA hint, and right above scrollable im-main__list.
        $stickyPos = strpos($blade, '<div class="im-main__sticky">');
        $hintPos = strpos($blade, 'idea_manager_ai_fill_hint');
        $groupBarPos = strpos($blade, 'data-im-group-bar="1"');
        $listPos = strpos($blade, '<div class="im-main__list">');
        self::assertNotFalse($stickyPos);
        self::assertNotFalse($hintPos);
        self::assertNotFalse($groupBarPos);
        self::assertNotFalse($listPos);
        self::assertGreaterThan($stickyPos, $hintPos);
        self::assertGreaterThan($hintPos, $groupBarPos);
        self::assertGreaterThan($groupBarPos, $listPos);
    }
}
