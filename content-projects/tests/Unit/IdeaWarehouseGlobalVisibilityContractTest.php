<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspaceClientSyncRules;
use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

/**
 * Production failure shape: site-4 Idea invisible while working/source site is site-1.
 */
final class IdeaWarehouseGlobalVisibilityContractTest extends TestCase
{
    public function test_dirty_site1_draft_does_not_hide_site4_capsule_idea(): void
    {
        $server = [
            [
                'source_type' => 'manual_seed',
                'cluster_ref' => 'manual:site1-a',
                'cluster_name_snapshot' => 'Idea A',
                'seed_text' => 'Idea A',
                'owner_site_id' => 1,
                'source_site_id' => 1,
                'source_domain' => 'site-one.test',
                'warehouse_key' => '1:manual:site1-a',
            ],
            [
                'source_type' => 'manual_seed',
                'cluster_ref' => 'manual:74b54447f21645ceb4b2ade0421c378e',
                'cluster_name_snapshot' => 'phong cách capsule wardrobe',
                'seed_text' => 'phong cách capsule wardrobe',
                'owner_site_id' => 4,
                'source_site_id' => 4,
                'source_domain' => 'maytuicanvas.com',
                'warehouse_key' => '4:manual:74b54447f21645ceb4b2ade0421c378e',
            ],
        ];

        // Dirty local draft while working workspace + source sidebar point at site 1.
        $draft = [
            [
                'source_type' => 'manual_seed',
                'cluster_ref' => 'manual:site1-a',
                'cluster_name_snapshot' => 'Idea A (edited)',
                'seed_text' => 'Idea A (edited)',
                'owner_site_id' => 1,
                'source_site_id' => 1,
                'source_domain' => 'site-one.test',
                'warehouse_key' => '1:manual:site1-a',
            ],
        ];

        $merged = IdeaWorkspaceClientSyncRules::reconcileGlobalWorkingSet(
            $server,
            $draft,
            ['1' => true],
        );

        self::assertCount(2, $merged);
        $capsule = null;
        foreach ($merged as $item) {
            if (($item['seed_text'] ?? '') === 'phong cách capsule wardrobe') {
                $capsule = $item;
                break;
            }
        }
        self::assertNotNull($capsule);
        self::assertSame(4, (int) ($capsule['owner_site_id'] ?? 0));
        self::assertSame('maytuicanvas.com', (string) ($capsule['source_domain'] ?? ''));
        self::assertSame('4:manual:74b54447f21645ceb4b2ade0421c378e', (string) ($capsule['warehouse_key'] ?? ''));

        // Local edit on site 1 preserved.
        self::assertSame('Idea A (edited)', (string) ($merged[0]['seed_text'] ?? $merged[1]['seed_text'] ?? ''));
        $edited = null;
        foreach ($merged as $item) {
            if ((int) ($item['owner_site_id'] ?? 0) === 1) {
                $edited = $item;
                break;
            }
        }
        self::assertSame('Idea A (edited)', (string) ($edited['seed_text'] ?? ''));

        $filtered = IdeaWorkspaceClientSyncRules::filterWarehouseItems(
            $merged,
            'phong cách capsule wardrobe',
            'all',
        );
        self::assertCount(1, $filtered);
        self::assertSame(4, (int) ($filtered[0]['owner_site_id'] ?? 0));

        // Source/target domain changes must not affect search (filter has no site args).
        $again = IdeaWorkspaceClientSyncRules::filterWarehouseItems(
            $merged,
            'phong cách capsule wardrobe',
            'manual',
        );
        self::assertCount(1, $again);
    }

    public function test_dirty_without_owner_map_still_appends_server_only_ideas(): void
    {
        $server = [
            [
                'cluster_ref' => 'manual:a',
                'seed_text' => 'A',
                'owner_site_id' => 1,
                'warehouse_key' => '1:manual:a',
            ],
            [
                'cluster_ref' => 'manual:capsule',
                'seed_text' => 'phong cách capsule wardrobe',
                'cluster_name_snapshot' => 'phong cách capsule wardrobe',
                'owner_site_id' => 4,
                'warehouse_key' => '4:manual:capsule',
            ],
        ];
        $draft = [
            [
                'cluster_ref' => 'manual:a',
                'seed_text' => 'A local',
                'owner_site_id' => 1,
                'warehouse_key' => '1:manual:a',
            ],
        ];
        $merged = IdeaWorkspaceClientSyncRules::reconcileGlobalWorkingSet($server, $draft, []);
        self::assertCount(2, $merged);
        $filtered = IdeaWorkspaceClientSyncRules::filterWarehouseItems($merged, 'capsule wardrobe');
        self::assertCount(1, $filtered);
    }

    public function test_alpine_calls_reconcile_and_never_blindly_assigns_dirty_draft(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');
        self::assertStringContainsString('reconcileGlobalWorkingSet', $blade);
        self::assertStringContainsString('NEVER replace the global warehouse with a single-site dirty draft', $blade);
        self::assertStringNotContainsString(
            'this.items = Array.isArray(draft.note_items) ? draft.note_items : [];',
            $blade,
        );
        self::assertStringContainsString('queueMicrotask(() => this.softReloadGlobal())', $blade);
        self::assertStringContainsString("// Global warehouse: search is independent of sourceFilterSiteId", $blade);
    }
}
