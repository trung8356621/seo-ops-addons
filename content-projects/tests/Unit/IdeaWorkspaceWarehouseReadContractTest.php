<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectIdeaManager;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspaceClientSyncRules;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspaceWarehouseReadService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Global Idea warehouse read-model + stale autosave protection contracts.
 */
final class IdeaWorkspaceWarehouseReadContractTest extends TestCase
{
    public function test_warehouse_read_service_stamps_owner_and_partitions(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaWorkspaceWarehouseReadService::class))->getFileName()
        );
        self::assertStringContainsString('function loadAuthorized', $src);
        self::assertStringContainsString('owner_site_id', $src);
        self::assertStringContainsString('warehouse_key', $src);
        self::assertStringContainsString('function partitionByOwnerSite', $src);
        self::assertStringContainsString('Never merges multi-site note_items into a single workspace write', $src);

        $parts = IdeaWorkspaceWarehouseReadService::partitionByOwnerSite([
            [
                'cluster_ref' => 'manual:a',
                'owner_site_id' => 4,
                'owner_domain' => 'a.test',
                'warehouse_key' => '4:manual:a',
                'seed_text' => 'x',
            ],
            [
                'cluster_ref' => 'manual:b',
                'source_site_id' => 7,
                'warehouse_key' => '7:manual:b',
            ],
        ]);
        self::assertArrayHasKey(4, $parts);
        self::assertArrayHasKey(7, $parts);
        self::assertArrayNotHasKey('owner_site_id', $parts[4][0]);
        self::assertArrayNotHasKey('warehouse_key', $parts[4][0]);
        self::assertSame('manual:a', $parts[4][0]['cluster_ref']);
    }

    public function test_idea_manager_uses_global_read_and_owner_save(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectIdeaManager::class))->getFileName()
        );
        self::assertStringContainsString('IdeaWorkspaceWarehouseReadService', $src);
        self::assertStringContainsString('loadAuthorized', $src);
        self::assertStringContainsString('function loadGlobalIdeaWarehouse', $src);
        self::assertStringContainsString('Strip warehouse-only keys', $src);
    }

    public function test_alpine_global_draft_and_no_stale_autosave(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');
        self::assertStringContainsString('seoOps:idea-manager:draft:v2:global', $blade);
        self::assertStringContainsString('loadGlobalIdeaWarehouse', $blade);
        self::assertStringContainsString('partitionByOwner', $blade);
        self::assertStringContainsString('detectRevisionConflicts', $blade);
        self::assertStringContainsString('softReloadGlobal', $blade);
        self::assertStringContainsString('seoOps:idea-manager:intake-ping', $blade);
        self::assertStringContainsString('// Global warehouse: search is independent of sourceFilterSiteId', $blade);
        // Source filter must not remount warehouse.
        self::assertStringContainsString('never remount warehouse', $blade);
    }

    public function test_stale_intake_revision_blocks_autosave(): void
    {
        $decision = IdeaWorkspaceClientSyncRules::reconcileGlobalOnOpen(
            true,
            ['4' => 2],
            ['4' => 3],
            [4],
        );
        self::assertTrue($decision['use_local']);
        self::assertTrue($decision['show_conflict']);
        self::assertFalse($decision['schedule_save']);
        self::assertSame(4, $decision['conflict_site_id']);

        $ok = IdeaWorkspaceClientSyncRules::reconcileGlobalOnOpen(
            true,
            ['4' => 3],
            ['4' => 3],
            [4],
        );
        self::assertFalse($ok['show_conflict']);
        self::assertTrue($ok['schedule_save']);
    }
}
